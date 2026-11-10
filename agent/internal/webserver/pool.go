package webserver

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"os"
	"regexp"
	"slices"
	"strings"

	"rayxo/agent/internal/validate"
)

// unitTemplatePattern matches the template unit file of a PHP version,
// rayxo-php84@.service for PHP 8.4.
var unitTemplatePattern = regexp.MustCompile(`^rayxo-php([0-9])([0-9])@\.service$`)

// phpFPMBinaryPattern matches the PHP-FPM binary of a PHP version in
// PHPFPMDir, php-fpm8.4 for PHP 8.4.
var phpFPMBinaryPattern = regexp.MustCompile(`^php-fpm([0-9]\.[0-9])$`)

// PHPVersions returns the installed PHP versions websites can use, oldest
// first: those with a PHP-FPM binary in PHPFPMDir.
func (m *Manager) PHPVersions() ([]string, error) {
	entries, err := os.ReadDir(m.cfg.PHPFPMDir)
	if errors.Is(err, os.ErrNotExist) {
		return []string{}, nil
	}

	if err != nil {
		return nil, fmt.Errorf("list %s: %w", m.cfg.PHPFPMDir, err)
	}

	versions := []string{}

	for _, entry := range entries {
		match := phpFPMBinaryPattern.FindStringSubmatch(entry.Name())
		if match != nil && m.PHPInstalled(match[1]) {
			versions = append(versions, match[1])
		}
	}

	slices.Sort(versions)

	return versions, nil
}

// removeUnusedPools deletes the pool sections of user that no Apache vhost
// of user sends PHP to anymore, such as that of a website that moved to
// another version or was removed, and reloads PHP-FPM without them. The
// pool of a version no website uses anymore is stopped, disabled and
// deleted. The caller holds the account lock, so no other change of the
// account can need such a pool meanwhile.
func (m *Manager) removeUnusedPools(ctx context.Context, user string) error {
	versions, err := m.poolVersions()
	if err != nil {
		return err
	}

	vhosts, err := m.apacheVhosts(user)
	if err != nil {
		return err
	}

	for _, version := range versions {
		if err := m.removeUnusedSections(ctx, user, version, vhosts); err != nil {
			return err
		}
	}

	return nil
}

// removeUnusedSections does removeUnusedPools for one version.
func (m *Manager) removeUnusedSections(ctx context.Context, user, version string, vhosts [][]byte) error {
	mainPath := m.poolFile(user, version)
	if !exists(mainPath) && !exists(m.poolSectionDir(user, version)) {
		return nil
	}

	domains, err := m.sectionDomains(user, version)
	if err != nil {
		return err
	}

	kept, removed := 0, false

	for _, domain := range domains {
		socket := m.sitePoolData(Site{User: user, PHPVersion: version, Domain: domain}).Socket
		if usesSocket(vhosts, socket) {
			kept++

			continue
		}

		if err := removeFile(m.poolSectionFile(user, version, domain)); err != nil {
			return err
		}

		removed = true
	}

	legacy := usesSocket(vhosts, m.legacyPoolData(user, version).Socket)

	if kept == 0 && !legacy {
		if err := m.stopPool(ctx, user, version); err != nil {
			return err
		}

		if err := m.deletePool(user, version); err != nil {
			return err
		}

		m.logger().Info("removed a PHP-FPM pool no website uses anymore", "user", user, "php_version", version)

		return nil
	}

	main, err := m.renderMaster(user, version, legacy, true)
	if err != nil {
		return err
	}

	mainChanged := !sameContent(mainPath, main)
	if mainChanged {
		if err := writeFileAtomic(mainPath, main, 0o644, nil); err != nil {
			return err
		}
	}

	if !removed && !mainChanged {
		return nil
	}

	unit := unitName(version, user)

	active, err := m.isActive(ctx, unit)
	if err != nil || !active {
		return err
	}

	return m.systemctl(ctx, "reload-or-restart", unit)
}

// applyPool installs the PHP-FPM pool of site and makes sure the pool of
// its account runs: started when it is not running, reloaded when its
// configuration changed. The pool of a suspended account is enabled but
// not started.
func (m *Manager) applyPool(ctx context.Context, site Site) error {
	unitChanged, err := m.ensureUnit(ctx, site.PHPVersion)
	if err != nil {
		return err
	}

	poolChanged, err := m.writePool(ctx, site)
	if err != nil {
		return err
	}

	unit := unitName(site.PHPVersion, site.User)

	if exists(m.suspendedFile(site.User)) {
		return m.systemctl(ctx, "enable", unit)
	}

	active, err := m.isActive(ctx, unit)
	if err != nil {
		return err
	}

	switch {
	case !active:
		return m.systemctl(ctx, "enable", "--now", unit)
	case unitChanged:
		// A reload does not pick up a new ExecStart.
		return m.systemctl(ctx, "restart", unit)
	case poolChanged:
		return m.systemctl(ctx, "reload-or-restart", unit)
	}

	return nil
}

// ensureUnit writes the systemd template unit of version when its content
// differs and reloads systemd. It reports whether the unit changed.
func (m *Manager) ensureUnit(ctx context.Context, version string) (bool, error) {
	content, err := render("php-fpm.service.tmpl", m.unitData(version))
	if err != nil {
		return false, err
	}

	m.mu.Lock()
	defer m.mu.Unlock()

	if err := ensureDir(m.cfg.UnitDir, 0o755); err != nil {
		return false, err
	}

	path := m.unitTemplate(version)

	current, err := os.ReadFile(path)
	if err == nil && bytes.Equal(current, content) {
		return false, nil
	}

	saved, err := applyChanges([]fileChange{{path: path, content: content}})
	if err != nil {
		return false, errors.Join(err, restoreFiles(saved))
	}

	if err := m.systemctl(ctx, "daemon-reload"); err != nil {
		// Put the old unit back, so the next call writes it again and
		// retries the reload.
		return false, errors.Join(err, restoreFiles(saved))
	}

	return true, nil
}

// writePool installs the pool section of site and the main configuration
// of its account's pool, which includes the section of every website. The
// main configuration keeps the account's old single pool while an Apache
// vhost of another website still uses it. PHP-FPM first checks the whole
// configuration as it will load it, so a rejected change is never
// installed. It reports whether anything changed.
func (m *Manager) writePool(ctx context.Context, site Site) (bool, error) {
	user, version := site.User, site.PHPVersion

	section, err := render("php-fpm-site.conf.tmpl", m.sitePoolData(site))
	if err != nil {
		return false, err
	}

	vhosts, err := m.apacheVhosts(user, site.Domain)
	if err != nil {
		return false, err
	}

	legacy := usesSocket(vhosts, m.legacyPoolData(user, version).Socket)

	main, err := m.renderMaster(user, version, legacy, true)
	if err != nil {
		return false, err
	}

	sectionPath := m.poolSectionFile(user, version, site.Domain)
	mainPath := m.poolFile(user, version)

	if sameContent(sectionPath, section) && sameContent(mainPath, main) {
		return false, nil
	}

	for _, dir := range []string{m.cfg.PoolDir, m.poolVersionDir(version)} {
		if err := ensureDir(dir, 0o755); err != nil {
			return false, err
		}
	}

	if err := m.checkPool(ctx, site, section, legacy); err != nil {
		return false, err
	}

	if err := ensureDir(m.poolSectionDir(user, version), 0o755); err != nil {
		return false, err
	}

	if err := writeFileAtomic(sectionPath, section, 0o644, nil); err != nil {
		return false, err
	}

	if err := writeFileAtomic(mainPath, main, 0o644, nil); err != nil {
		return false, err
	}

	return true, nil
}

// checkPool has PHP-FPM check the configuration of the pool of site's
// account as it would be with section in place: the main part, then every
// section inline. Refusals are ErrPoolRejected.
func (m *Manager) checkPool(ctx context.Context, site Site, section []byte, legacy bool) error {
	user, version := site.User, site.PHPVersion

	config, err := m.renderMaster(user, version, legacy, false)
	if err != nil {
		return err
	}

	domains, err := m.sectionDomains(user, version)
	if err != nil {
		return err
	}

	for _, domain := range domains {
		if domain == site.Domain {
			continue
		}

		other, err := os.ReadFile(m.poolSectionFile(user, version, domain))
		if err != nil {
			return fmt.Errorf("read the pool of %s: %w", domain, err)
		}

		config = append(config, other...)
	}

	config = append(config, section...)

	temp, err := os.CreateTemp(m.poolVersionDir(version), "."+user+".conf.tmp-*")
	if err != nil {
		return fmt.Errorf("check the pool of %s: %w", user, err)
	}

	defer func() { _ = os.Remove(temp.Name()) }()

	_, err = temp.Write(config)
	if closeErr := temp.Close(); err == nil {
		err = closeErr
	}

	if err != nil {
		return fmt.Errorf("check the pool of %s: %w", user, err)
	}

	if _, err := m.cfg.Run(ctx, m.phpFPMBinary(version), "-t", "--fpm-config", temp.Name()); err != nil {
		m.logger().Error("PHP-FPM rejected a pool configuration", "user", user, "php_version", version, "domain", site.Domain, "error", err)

		return fmt.Errorf("%w: PHP %s, account %s", ErrPoolRejected, version, user)
	}

	return nil
}

// renderMaster returns the main configuration of the pool of user for
// version: the master's settings, then either the include of the website
// sections or (for checking) nothing, and the account's old single pool
// when legacy is set.
func (m *Manager) renderMaster(user, version string, legacy, include bool) ([]byte, error) {
	content, err := render("php-fpm-pool.conf.tmpl", m.masterData(user, version, include))
	if err != nil {
		return nil, err
	}

	if !legacy {
		return content, nil
	}

	old, err := render("php-fpm-site.conf.tmpl", m.legacyPoolData(user, version))
	if err != nil {
		return nil, err
	}

	return append(content, old...), nil
}

// sectionDomains returns the domains that have a pool section of user on
// version, in order.
func (m *Manager) sectionDomains(user, version string) ([]string, error) {
	dir := m.poolSectionDir(user, version)

	entries, err := os.ReadDir(dir)
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}

	if err != nil {
		return nil, fmt.Errorf("list %s: %w", dir, err)
	}

	var domains []string

	for _, entry := range entries {
		domain, ok := strings.CutSuffix(entry.Name(), ".conf")
		if ok && entry.Type().IsRegular() && validate.Domain(domain) == nil {
			domains = append(domains, domain)
		}
	}

	return domains, nil
}

// deletePool removes the main configuration and the website sections of
// the pool of user for version.
func (m *Manager) deletePool(user, version string) error {
	if err := removeFile(m.poolFile(user, version)); err != nil {
		return err
	}

	dir := m.poolSectionDir(user, version)
	if err := os.RemoveAll(dir); err != nil {
		return fmt.Errorf("remove %s: %w", dir, err)
	}

	return nil
}

// apacheVhosts returns the content of the Apache vhosts of user, except
// those of the domains in except.
func (m *Manager) apacheVhosts(user string, except ...string) ([][]byte, error) {
	dir := m.apacheAccountDir(user)

	entries, err := os.ReadDir(dir)
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}

	if err != nil {
		return nil, fmt.Errorf("list %s: %w", dir, err)
	}

	var vhosts [][]byte

	for _, entry := range entries {
		domain, ok := strings.CutSuffix(entry.Name(), ".conf")
		if !ok || !entry.Type().IsRegular() || slices.Contains(except, domain) {
			continue
		}

		content, err := os.ReadFile(dir + "/" + entry.Name())
		if err != nil {
			return nil, fmt.Errorf("read %s/%s: %w", dir, entry.Name(), err)
		}

		vhosts = append(vhosts, content)
	}

	return vhosts, nil
}

// usesSocket reports whether one of vhosts sends PHP to socket.
func usesSocket(vhosts [][]byte, socket string) bool {
	marker := []byte("proxy:unix:" + socket + "|")

	for _, vhost := range vhosts {
		if bytes.Contains(vhost, marker) {
			return true
		}
	}

	return false
}

// sameContent reports whether the file at path holds exactly content.
func sameContent(path string, content []byte) bool {
	current, err := os.ReadFile(path)

	return err == nil && bytes.Equal(current, content)
}

// stopPool stops and disables the pool unit of user for version. Without
// the template unit no such pool can have run, so nothing is done.
func (m *Manager) stopPool(ctx context.Context, user, version string) error {
	if !exists(m.unitTemplate(version)) {
		return nil
	}

	return m.systemctl(ctx, "disable", "--now", unitName(version, user))
}

// domainsUsingPool returns the domains of user whose Apache vhost sends PHP
// to the pool of version.
func (m *Manager) domainsUsingPool(user, version string) ([]string, error) {
	dir := m.apacheAccountDir(user)

	entries, err := os.ReadDir(dir)
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}

	if err != nil {
		return nil, fmt.Errorf("list %s: %w", dir, err)
	}

	// The account's old single pool, or the pool of one of its websites.
	legacy := []byte("proxy:unix:" + m.legacyPoolData(user, version).Socket + "|")
	perSite := []byte("proxy:unix:" + m.cfg.RunDir + "/" + m.masterName(user, version) + "-")

	var domains []string

	for _, entry := range entries {
		domain, ok := strings.CutSuffix(entry.Name(), ".conf")
		if !ok || !entry.Type().IsRegular() {
			continue
		}

		content, err := os.ReadFile(dir + "/" + entry.Name())
		if err != nil {
			return nil, fmt.Errorf("read %s/%s: %w", dir, entry.Name(), err)
		}

		if bytes.Contains(content, legacy) || bytes.Contains(content, perSite) {
			domains = append(domains, domain)
		}
	}

	return domains, nil
}

// unitVersions returns the PHP versions that have a template unit, which
// are all versions an account's pool may run with.
func (m *Manager) unitVersions() ([]string, error) {
	entries, err := os.ReadDir(m.cfg.UnitDir)
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}

	if err != nil {
		return nil, fmt.Errorf("list %s: %w", m.cfg.UnitDir, err)
	}

	var versions []string

	for _, entry := range entries {
		if match := unitTemplatePattern.FindStringSubmatch(entry.Name()); match != nil {
			versions = append(versions, match[1]+"."+match[2])
		}
	}

	slices.Sort(versions)

	return versions, nil
}

// poolFiles returns the pool configurations of user, one per PHP version.
func (m *Manager) poolFiles(user string) ([]string, error) {
	entries, err := os.ReadDir(m.cfg.PoolDir)
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}

	if err != nil {
		return nil, fmt.Errorf("list %s: %w", m.cfg.PoolDir, err)
	}

	var pools []string

	for _, entry := range entries {
		if !entry.IsDir() || !phpVersionDir(entry.Name()) {
			continue
		}

		if path := m.poolFile(user, entry.Name()); exists(path) {
			pools = append(pools, path)
		}
	}

	return pools, nil
}

// poolVersions returns the PHP versions that have a pool directory.
func (m *Manager) poolVersions() ([]string, error) {
	entries, err := os.ReadDir(m.cfg.PoolDir)
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}

	if err != nil {
		return nil, fmt.Errorf("list %s: %w", m.cfg.PoolDir, err)
	}

	var versions []string

	for _, entry := range entries {
		if entry.IsDir() && phpVersionDir(entry.Name()) {
			versions = append(versions, entry.Name())
		}
	}

	return versions, nil
}
