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
)

// unitTemplatePattern matches the template unit file of a PHP version,
// nusa-php84@.service for PHP 8.4.
var unitTemplatePattern = regexp.MustCompile(`^nusa-php([0-9])([0-9])@\.service$`)

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
