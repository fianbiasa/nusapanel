package webserver

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strings"
)

// unitTemplatePattern matches the template unit file of a PHP version,
// nusa-php84@.service for PHP 8.4.
var unitTemplatePattern = regexp.MustCompile(`^nusa-php([0-9])([0-9])@\.service$`)

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

// removeUnusedPools stops, disables and deletes every PHP-FPM pool of user
// that no Apache vhost of user uses anymore, such as the pool of the
// version a website just moved away from. The caller holds the account
// lock, so no other change of the account can need such a pool meanwhile.
func (m *Manager) removeUnusedPools(ctx context.Context, user string) error {
	pools, err := m.poolFiles(user)
	if err != nil {
		return err
	}

	for _, pool := range pools {
		version := filepath.Base(filepath.Dir(pool))

		domains, err := m.domainsUsingPool(user, version)
		if err != nil {
			return err
		}

		if len(domains) > 0 {
			continue
		}

		if err := m.stopPool(ctx, user, version); err != nil {
			return err
		}

		if err := removeFile(pool); err != nil {
			return err
		}

		m.logger().Info("removed a PHP-FPM pool no website uses anymore", "user", user, "php_version", version)
	}

	return nil
}

// applyPool installs the PHP-FPM pool of user for version and makes sure it
// runs: started when it is not running, reloaded when its configuration
// changed. The pool of a suspended account is enabled but not started.
func (m *Manager) applyPool(ctx context.Context, user, version string) error {
	unitChanged, err := m.ensureUnit(ctx, version)
	if err != nil {
		return err
	}

	poolChanged, err := m.writePool(ctx, user, version)
	if err != nil {
		return err
	}

	unit := unitName(version, user)

	if exists(m.suspendedFile(user)) {
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

// writePool installs the pool configuration of user for version when its
// content differs, after PHP-FPM accepted it. It reports whether the pool
// changed.
func (m *Manager) writePool(ctx context.Context, user, version string) (bool, error) {
	content, err := render("php-fpm-pool.conf.tmpl", m.poolData(user, version))
	if err != nil {
		return false, err
	}

	for _, dir := range []string{m.cfg.PoolDir, m.poolVersionDir(version)} {
		if err := ensureDir(dir, 0o755); err != nil {
			return false, err
		}
	}

	path := m.poolFile(user, version)

	current, err := os.ReadFile(path)
	if err == nil && bytes.Equal(current, content) {
		return false, nil
	}

	if err != nil && !errors.Is(err, os.ErrNotExist) {
		return false, fmt.Errorf("read %s: %w", path, err)
	}

	err = writeFileAtomic(path, content, 0o644, func(temp string) error {
		_, err := m.cfg.Run(ctx, m.phpFPMBinary(version), "-t", "--fpm-config", temp)
		if err != nil {
			m.logger().Error("PHP-FPM rejected a pool configuration", "user", user, "php_version", version, "error", err)

			return fmt.Errorf("%w: PHP %s, account %s", ErrPoolRejected, version, user)
		}

		return nil
	})
	if err != nil {
		return false, err
	}

	return true, nil
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

	socket := []byte("proxy:unix:" + m.poolData(user, version).Socket + "|")

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

		if bytes.Contains(content, socket) {
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
