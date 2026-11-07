// Package webserver manages the websites of hosting accounts: an Nginx vhost
// and an Apache vhost per domain, a PHP-FPM pool per account per PHP
// version that runs as its own systemd service (nusa-php<ver>@<user>), and
// a Let's Encrypt certificate per domain (certificate.go). See
// docs/02-arsitektur.md 2.6-2.7 and docs/04-agent-dan-server.md 4.4-4.6.
//
// Every value written into a config file comes from a validated username,
// domain or PHP version, or from Config; each method validates its inputs
// again, so a caller's mistake can never inject config syntax.
package webserver

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"os"
	"slices"
	"strings"
	"sync"

	"nusapanel/agent/internal/runner"
	"nusapanel/agent/internal/sysuser"
	"nusapanel/agent/internal/validate"
)

// Programs used, by absolute path as installed on Ubuntu 24.04.
const (
	nginxProgram     = "/usr/sbin/nginx"
	apachectlProgram = "/usr/sbin/apache2ctl"
	systemctlProgram = "/usr/bin/systemctl"
	certbotProgram   = "/usr/bin/certbot"
)

var (
	// ErrConfigRejected means Nginx or Apache refused the new configuration.
	// The files were restored to what they were before and the services
	// were not reloaded.
	ErrConfigRejected = errors.New("the web server rejected the configuration")
	// ErrPoolRejected means PHP-FPM refused a new pool configuration, which
	// was not installed.
	ErrPoolRejected = errors.New("PHP-FPM rejected the pool configuration")
	// ErrNoCertificate means HTTPS was asked for a domain that has no
	// certificate yet. Nothing was changed.
	ErrNoCertificate = errors.New("the domain has no certificate")
)

// Config holds every location the Manager reads or writes and the
// operations it needs from the system, so tests can run without root.
type Config struct {
	// NginxDir holds one directory per account with a vhost per domain,
	// <NginxDir>/<user>/<domain>.conf, and suspended.inc while the account
	// is suspended.
	NginxDir string
	// ApacheDir holds <ApacheDir>/<user>/<domain>.conf.
	ApacheDir string
	// PoolDir holds the PHP-FPM pools, <PoolDir>/<version>/<user>.conf.
	PoolDir string
	// UnitDir is where the systemd template units nusa-php<ver>@.service go.
	UnitDir string
	// LogDir holds <domain>.access.log, .error.log and .apache-error.log.
	LogDir string
	// LogGroup is the group of LogDir; empty leaves the group as it is.
	LogGroup string
	// RunDir holds the pools' sockets and pid files. systemd creates it as
	// the units' RuntimeDirectory, so it must be directly inside /run.
	RunDir string
	// HomeRoot holds the account homes, <HomeRoot>/<user>.
	HomeRoot string
	// PHPFPMDir is where php-fpm<version> of every installed PHP lives.
	PHPFPMDir string
	// IPv6File has content when the server has IPv6 (/proc/net/if_inet6).
	IPv6File string
	// Backend is the address Apache listens on, behind Nginx.
	Backend string
	// AcmeDir is the webroot certbot puts Let's Encrypt challenges in; every
	// vhost serves its /.well-known/acme-challenge/ on port 80.
	AcmeDir string
	// LetsEncryptDir is certbot's configuration folder, with the current
	// certificates in live/<domain>/ and their settings in
	// renewal/<domain>.conf.
	LetsEncryptDir string

	// Hostnames returns the server's own names, lowercased. No website may
	// use one of them.
	Hostnames func() []string
	// Run runs a program without a shell, like runner.Run.
	Run func(ctx context.Context, program string, args ...string) ([]byte, error)
	// PrepareSite creates a website's folders inside a home, like
	// sysuser.System.PrepareSite.
	PrepareSite func(ctx context.Context, site sysuser.Site) error
	// DialHTTP opens the connections of the check that a domain reaches
	// this server before a certificate is requested. Defaults to dialing
	// public addresses only.
	DialHTTP func(ctx context.Context, network, address string) (net.Conn, error)
	// Logger receives the output of rejected config tests. Defaults to
	// slog.Default().
	Logger *slog.Logger
}

// DefaultConfig returns the locations and operations used on a server.
func DefaultConfig() Config {
	return Config{
		NginxDir:       "/etc/nginx/nusapanel",
		ApacheDir:      "/etc/apache2/nusapanel",
		PoolDir:        "/etc/nusapanel/php-fpm",
		UnitDir:        "/etc/systemd/system",
		LogDir:         "/var/log/nusapanel/domains",
		LogGroup:       "adm",
		RunDir:         "/run/nusa-php",
		HomeRoot:       "/home",
		PHPFPMDir:      "/usr/sbin",
		IPv6File:       "/proc/net/if_inet6",
		Backend:        "127.0.0.1:8080",
		AcmeDir:        "/var/www/nusapanel-acme",
		LetsEncryptDir: "/etc/letsencrypt",
		Hostnames:      systemHostnames,
		Run:            runner.Run,
		PrepareSite:    sysuser.System{}.PrepareSite,
		DialHTTP:       dialPublic,
	}
}

// systemHostnames returns the kernel hostname and the name setup.sh gave
// the server (stored in /etc/mailname), which may be the longer FQDN.
func systemHostnames() []string {
	var names []string

	if name, err := os.Hostname(); err == nil {
		names = append(names, normalizeHostname(name))
	}

	if data, err := os.ReadFile("/etc/mailname"); err == nil {
		line, _, _ := strings.Cut(string(data), "\n")
		names = append(names, normalizeHostname(line))
	}

	return names
}

func normalizeHostname(name string) string {
	return strings.TrimSuffix(strings.ToLower(strings.TrimSpace(name)), ".")
}

// Manager applies website configuration. Callers must make sure only one
// call changes a given account at a time (the agent's account lock); the
// Manager itself serializes what is shared between accounts.
type Manager struct {
	cfg Config

	// mu is held for every change to server-wide state: the Nginx and
	// Apache configuration, from writing the files through testing to
	// reloading (nginx -t and apache2ctl test the whole server, not one
	// file), and the unit templates every account's pools share.
	mu sync.Mutex
}

// New returns a Manager for cfg. It does not touch the system.
func New(cfg Config) *Manager {
	return &Manager{cfg: cfg}
}

// Site is one domain of an account, served with one PHP version.
type Site struct {
	User       string
	UID        int
	GID        int
	Domain     string
	PHPVersion string
	// SSL serves the domain over HTTPS too, with its Let's Encrypt
	// certificate, which must exist.
	SSL bool
	// ForceHTTPS redirects HTTP to HTTPS, except Let's Encrypt challenges.
	// It needs SSL.
	ForceHTTPS bool
}

// PHPInstalled reports whether PHP-FPM of version is installed.
func (m *Manager) PHPInstalled(version string) bool {
	if validate.PHPVersion(version) != nil {
		return false
	}

	info, err := os.Stat(m.phpFPMBinary(version))

	return err == nil && info.Mode().IsRegular()
}

// IsHostname reports whether domain is one of the server's own names.
func (m *Manager) IsHostname(domain string) bool {
	for _, name := range m.cfg.Hostnames() {
		if name != "" && name == domain {
			return true
		}
	}

	return false
}

// UsedByOtherAccount reports whether an account other than user has a vhost
// that answers to domain: one for domain itself, for www.<domain>, or, when
// domain starts with www., for the name without it.
func (m *Manager) UsedByOtherAccount(domain, user string) (bool, error) {
	names := []string{domain, "www." + domain}
	if bare, ok := strings.CutPrefix(domain, "www."); ok && validate.Domain(bare) == nil {
		names = append(names, bare)
	}

	entries, err := os.ReadDir(m.cfg.NginxDir)
	if errors.Is(err, os.ErrNotExist) {
		return false, nil
	}

	if err != nil {
		return false, fmt.Errorf("list %s: %w", m.cfg.NginxDir, err)
	}

	for _, entry := range entries {
		if !entry.IsDir() || entry.Name() == user {
			continue
		}

		for _, name := range names {
			_, err := os.Lstat(m.nginxVhost(entry.Name(), name))
			if err == nil {
				return true, nil
			}

			if !errors.Is(err, os.ErrNotExist) {
				return false, err
			}
		}
	}

	return false, nil
}

// DocumentRoot returns the folder domain of user is served from.
func (m *Manager) DocumentRoot(user, domain string) string {
	return m.home(user) + "/domains/" + domain + "/public_html"
}

// ApplyDomain creates the folders of site, its PHP-FPM pool and its Nginx
// and Apache vhosts, and returns its document root. Running it again
// renders the same configuration, so a failed call can be retried. HTTPS
// for a domain without a certificate fails with ErrNoCertificate before
// anything changes.
func (m *Manager) ApplyDomain(ctx context.Context, site Site) (string, error) {
	if err := checkSite(site); err != nil {
		return "", err
	}

	if site.SSL && !m.HasCertificate(site.Domain) {
		return "", fmt.Errorf("%w: %s", ErrNoCertificate, site.Domain)
	}

	index, err := render("index.html.tmpl", m.siteData(site))
	if err != nil {
		return "", err
	}

	err = m.cfg.PrepareSite(ctx, sysuser.Site{
		Home: m.home(site.User),
		Dirs: []sysuser.Dir{
			{Name: "domains", Mode: 0o711},
			{Name: site.Domain, Mode: 0o711},
			{Name: "public_html", Mode: 0o755},
		},
		UID:   site.UID,
		GID:   site.GID,
		Index: index,
	})
	if err != nil {
		return "", fmt.Errorf("prepare the folders of %s: %w", site.Domain, err)
	}

	if err := m.ensureLogDir(); err != nil {
		return "", err
	}

	if err := m.applyPool(ctx, site.User, site.PHPVersion); err != nil {
		return "", err
	}

	if err := m.applyVhosts(ctx, site); err != nil {
		return "", err
	}

	return m.DocumentRoot(site.User, site.Domain), nil
}

// RemoveDomain removes the Nginx and Apache vhosts of domain, reloads both,
// then deletes the domain's logs and its certificate, so a later owner of
// the same domain never inherits them and account.delete leaves nothing
// behind. The domain's files in the home are kept. Removing a domain
// without configuration succeeds.
func (m *Manager) RemoveDomain(ctx context.Context, user, domain string) error {
	if err := checkNames(user, domain); err != nil {
		return err
	}

	if err := m.removeDomainVhosts(ctx, user, domain); err != nil {
		return err
	}

	if err := m.removeLogs([]string{domain}); err != nil {
		return err
	}

	return m.deleteUnusedCertificates(ctx, []string{domain})
}

// removeDomainVhosts deletes the vhosts of domain, then tests and reloads
// Nginx and Apache.
func (m *Manager) removeDomainVhosts(ctx context.Context, user, domain string) error {
	m.mu.Lock()
	defer m.mu.Unlock()

	return m.commit(ctx, []fileChange{
		{path: m.nginxVhost(user, domain)},
		{path: m.apacheVhost(user, domain)},
	}, nginxService, apacheService)
}

// RemovePool stops and disables the PHP-FPM pool of user for version and
// deletes its configuration. A missing pool is not an error. A pool that an
// Apache vhost of user still uses is kept: the panel may send this call
// after a newer web.apply_domain that needs the same pool again.
func (m *Manager) RemovePool(ctx context.Context, user, version string) error {
	if err := checkPool(user, version); err != nil {
		return err
	}

	domains, err := m.domainsUsingPool(user, version)
	if err != nil {
		return err
	}

	if len(domains) > 0 {
		m.logger().Info("PHP-FPM pool still in use; not removed",
			"user", user, "php_version", version, "domains", domains)

		return nil
	}

	if err := m.stopPool(ctx, user, version); err != nil {
		return err
	}

	return removeFile(m.poolFile(user, version))
}

// SuspendAccount makes every website of user answer with a "temporarily
// unavailable" page and stops the account's PHP-FPM pools.
func (m *Manager) SuspendAccount(ctx context.Context, user string) error {
	if err := validate.Username(user); err != nil {
		return err
	}

	page, err := render("suspended.inc.tmpl", m.accountData(user))
	if err != nil {
		return err
	}

	if err := m.changeSuspension(ctx, user, page); err != nil {
		return err
	}

	versions, err := m.unitVersions()
	if err != nil {
		return err
	}

	for _, version := range versions {
		if err := m.systemctl(ctx, "stop", unitName(version, user)); err != nil {
			return err
		}
	}

	return nil
}

// UnsuspendAccount serves the websites of user again and starts the
// account's PHP-FPM pools.
func (m *Manager) UnsuspendAccount(ctx context.Context, user string) error {
	if err := validate.Username(user); err != nil {
		return err
	}

	if err := m.changeSuspension(ctx, user, nil); err != nil {
		return err
	}

	versions, err := m.unitVersions()
	if err != nil {
		return err
	}

	for _, version := range versions {
		if !exists(m.poolFile(user, version)) {
			continue
		}

		if err := m.systemctl(ctx, "start", unitName(version, user)); err != nil {
			return err
		}
	}

	return nil
}

// RemoveAccount removes everything web related of user: its PHP-FPM pools
// (stopped and disabled first), the Nginx and Apache vhosts of all its
// domains, and those domains' logs and certificates. It succeeds for an
// account that has none of these, so it can be repeated; a repeat after a
// failure also finds the domains whose vhosts the failed call removed.
func (m *Manager) RemoveAccount(ctx context.Context, user string) error {
	if err := validate.Username(user); err != nil {
		return err
	}

	versions, err := m.unitVersions()
	if err != nil {
		return err
	}

	for _, version := range versions {
		if err := m.systemctl(ctx, "disable", "--now", unitName(version, user)); err != nil {
			return err
		}
	}

	pools, err := m.poolFiles(user)
	if err != nil {
		return err
	}

	for _, pool := range pools {
		if err := removeFile(pool); err != nil {
			return err
		}
	}

	domains, err := m.removeVhosts(ctx, user)
	if err != nil {
		return err
	}

	if err := m.removeLogs(domains); err != nil {
		return err
	}

	if err := m.deleteUnusedCertificates(ctx, domains); err != nil {
		return err
	}

	for _, dir := range []string{m.nginxAccountDir(user), m.apacheAccountDir(user)} {
		if err := os.RemoveAll(dir); err != nil {
			return fmt.Errorf("remove %s: %w", dir, err)
		}
	}

	return nil
}

// removeVhosts deletes every file in the vhost directories of user, tests
// and reloads Nginx and Apache, and returns the domains that had a vhost,
// together with those an earlier, unfinished call removed. Until the
// directories themselves are removed, they keep that list in
// removedDomainsFile, so a retry can still delete the domains' certificates.
func (m *Manager) removeVhosts(ctx context.Context, user string) ([]string, error) {
	m.mu.Lock()
	defer m.mu.Unlock()

	accountDirs := []string{m.nginxAccountDir(user), m.apacheAccountDir(user)}
	listFile := m.removedDomainsFile(user)

	domains, err := readDomainList(listFile)
	if err != nil {
		return nil, err
	}

	var changes []fileChange

	for _, dir := range accountDirs {
		entries, err := os.ReadDir(dir)
		if errors.Is(err, os.ErrNotExist) {
			continue
		}

		if err != nil {
			return nil, fmt.Errorf("list %s: %w", dir, err)
		}

		for _, entry := range entries {
			path := dir + "/" + entry.Name()
			if !entry.Type().IsRegular() || path == listFile {
				continue
			}

			changes = append(changes, fileChange{path: path})

			domain, ok := strings.CutSuffix(entry.Name(), ".conf")
			if ok && validate.Domain(domain) == nil && !slices.Contains(domains, domain) {
				domains = append(domains, domain)
			}
		}
	}

	if len(domains) > 0 {
		if err := ensureDir(m.nginxAccountDir(user), 0o755); err != nil {
			return nil, err
		}

		// Part of the commit, so a rejected change takes the list back too.
		list := []byte(strings.Join(domains, "\n") + "\n")
		changes = append([]fileChange{{path: listFile, content: list}}, changes...)
	}

	if err := m.commit(ctx, changes, nginxService, apacheService); err != nil {
		return nil, err
	}

	return domains, nil
}

// readDomainList returns the valid domains listed one per line in path, or
// none when path does not exist.
func readDomainList(path string) ([]string, error) {
	content, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}

	if err != nil {
		return nil, fmt.Errorf("read %s: %w", path, err)
	}

	var domains []string

	for line := range strings.Lines(string(content)) {
		domain := strings.TrimSpace(line)
		if validate.Domain(domain) == nil && !slices.Contains(domains, domain) {
			domains = append(domains, domain)
		}
	}

	return domains, nil
}

// changeSuspension writes the suspended page of user, or removes it when
// page is nil, then tests and reloads Nginx.
func (m *Manager) changeSuspension(ctx context.Context, user string, page []byte) error {
	m.mu.Lock()
	defer m.mu.Unlock()

	if page != nil {
		if err := ensureDir(m.nginxAccountDir(user), 0o755); err != nil {
			return err
		}
	}

	return m.commit(ctx, []fileChange{{path: m.suspendedFile(user), content: page}}, nginxService)
}

// applyVhosts writes the Nginx and Apache vhosts of site, then tests and
// reloads both servers.
func (m *Manager) applyVhosts(ctx context.Context, site Site) error {
	data := m.siteData(site)

	nginx, err := render("nginx-vhost.conf.tmpl", data)
	if err != nil {
		return err
	}

	apache, err := render("apache-vhost.conf.tmpl", data)
	if err != nil {
		return err
	}

	m.mu.Lock()
	defer m.mu.Unlock()

	for _, dir := range []string{m.cfg.NginxDir, m.nginxAccountDir(site.User), m.cfg.ApacheDir, m.apacheAccountDir(site.User)} {
		if err := ensureDir(dir, 0o755); err != nil {
			return err
		}
	}

	return m.commit(ctx, []fileChange{
		{path: m.nginxVhost(site.User, site.Domain), content: nginx},
		{path: m.apacheVhost(site.User, site.Domain), content: apache},
	}, nginxService, apacheService)
}

// ensureLogDir creates the per-domain log directory, root-owned with mode
// 0750 and the log group, so only administrators can read the logs.
func (m *Manager) ensureLogDir() error {
	dir := m.cfg.LogDir

	if err := ensureDir(dir, 0o750); err != nil {
		return err
	}

	if m.cfg.LogGroup == "" {
		return nil
	}

	gid, err := lookupGroup(m.cfg.LogGroup)
	if err != nil {
		return err
	}

	if err := os.Lchown(dir, -1, gid); err != nil {
		return fmt.Errorf("chown %s: %w", dir, err)
	}

	return nil
}

// systemctl runs systemctl with args.
func (m *Manager) systemctl(ctx context.Context, args ...string) error {
	if _, err := m.cfg.Run(ctx, systemctlProgram, args...); err != nil {
		return fmt.Errorf("systemctl %s: %w", strings.Join(args, " "), err)
	}

	return nil
}

// isActive reports whether unit is running.
func (m *Manager) isActive(ctx context.Context, unit string) (bool, error) {
	_, err := m.cfg.Run(ctx, systemctlProgram, "is-active", "--quiet", unit)
	if err == nil {
		return true, nil
	}

	// is-active exits with a non-zero code for every state but active.
	if runner.ExitCode(err) > 0 {
		return false, nil
	}

	return false, fmt.Errorf("check whether %s is running: %w", unit, err)
}

func (m *Manager) logger() *slog.Logger {
	if m.cfg.Logger != nil {
		return m.cfg.Logger
	}

	return slog.Default()
}

// checkSite validates every field of site that reaches a config file.
func checkSite(site Site) error {
	if err := checkNames(site.User, site.Domain); err != nil {
		return err
	}

	if site.ForceHTTPS && !site.SSL {
		return errors.New("forcing HTTPS needs SSL")
	}

	return validate.PHPVersion(site.PHPVersion)
}

func checkNames(user, domain string) error {
	if err := validate.Username(user); err != nil {
		return err
	}

	return validate.Domain(domain)
}

func checkPool(user, version string) error {
	if err := validate.Username(user); err != nil {
		return err
	}

	return validate.PHPVersion(version)
}
