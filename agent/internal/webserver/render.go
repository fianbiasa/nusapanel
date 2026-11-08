package webserver

import (
	"bytes"
	"embed"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"text/template"
)

// The default templates, built into the binary.
//
//go:embed templates/*.tmpl
var templateFiles embed.FS

var templates = template.Must(template.New("").Option("missingkey=error").ParseFS(templateFiles, "templates/*.tmpl"))

var phpVersionDirPattern = regexp.MustCompile(`^[0-9]\.[0-9]$`)

// siteData is what the vhost and index.html templates see.
type siteData struct {
	User            string
	Domain          string
	DocumentRoot    string
	LogDir          string
	NginxAccountDir string
	Backend         string
	IPv6            bool
	// Socket and PoolName identify the account's PHP-FPM pool.
	Socket   string
	PoolName string
	// AcmeDir is the webroot of Let's Encrypt challenges.
	AcmeDir string
	// SSL adds a server on port 443 with Certificate and CertificateKey;
	// ForceHTTPS redirects port 80 to it.
	SSL            bool
	ForceHTTPS     bool
	Certificate    string
	CertificateKey string
	// ForwardTo is the domain a forward redirects every request to; empty
	// for a site that serves a folder.
	ForwardTo string
	// Redirects are the site's URL redirects, in the order Nginx checks
	// them.
	Redirects []redirectData
}

// poolData is what the pool template sees.
type poolData struct {
	User       string
	PHPVersion string
	PHPTag     string
	Home       string
	RunDir     string
	Socket     string
	PoolName   string
}

// unitData is what the systemd template unit sees.
type unitData struct {
	PHPVersion       string
	PHPTag           string
	Binary           string
	PoolDir          string
	NginxDir         string
	RuntimeDirectory string
}

// accountData is what the suspended page template sees.
type accountData struct {
	User string
}

// render executes the named template with data.
func render(name string, data any) ([]byte, error) {
	var out bytes.Buffer
	if err := templates.ExecuteTemplate(&out, name, data); err != nil {
		return nil, fmt.Errorf("render %s: %w", name, err)
	}

	return out.Bytes(), nil
}

func (m *Manager) siteData(site Site) siteData {
	pool := m.poolData(site.User, site.PHPVersion)

	var forwardTo string
	if site.kind() == KindForward {
		forwardTo = site.Parent
	}

	return siteData{
		User:            site.User,
		Domain:          site.Domain,
		DocumentRoot:    m.siteDocumentRoot(site),
		ForwardTo:       forwardTo,
		Redirects:       redirectsData(site.Redirects),
		LogDir:          m.cfg.LogDir,
		NginxAccountDir: m.nginxAccountDir(site.User),
		Backend:         m.cfg.Backend,
		IPv6:            m.hasIPv6(),
		Socket:          pool.Socket,
		PoolName:        pool.PoolName,
		AcmeDir:         m.cfg.AcmeDir,
		SSL:             site.SSL,
		ForceHTTPS:      site.ForceHTTPS,
		Certificate:     m.certificateFile(site.Domain),
		CertificateKey:  m.certificateKeyFile(site.Domain),
	}
}

func (m *Manager) poolData(user, version string) poolData {
	tag := phpTag(version)
	name := "php" + tag + "-" + user

	return poolData{
		User:       user,
		PHPVersion: version,
		PHPTag:     tag,
		Home:       m.home(user),
		RunDir:     m.cfg.RunDir,
		Socket:     m.cfg.RunDir + "/" + name + ".sock",
		PoolName:   name,
	}
}

func (m *Manager) unitData(version string) unitData {
	return unitData{
		PHPVersion:       version,
		PHPTag:           phpTag(version),
		Binary:           m.phpFPMBinary(version),
		PoolDir:          m.poolVersionDir(version),
		NginxDir:         m.cfg.NginxDir,
		RuntimeDirectory: filepath.Base(m.cfg.RunDir),
	}
}

func (m *Manager) accountData(user string) accountData {
	return accountData{User: user}
}

// hasIPv6 reports whether the server has IPv6. procfs files report size 0,
// so the content is read rather than the size checked.
func (m *Manager) hasIPv6() bool {
	content, err := os.ReadFile(m.cfg.IPv6File)

	return err == nil && len(bytes.TrimSpace(content)) > 0
}

// phpTag returns version without the dot: "84" for "8.4".
func phpTag(version string) string {
	return strings.ReplaceAll(version, ".", "")
}

// phpVersionDir reports whether name is a PHP version directory in PoolDir.
func phpVersionDir(name string) bool {
	return phpVersionDirPattern.MatchString(name)
}

// unitName returns the systemd unit of the pool of user for version.
func unitName(version, user string) string {
	return "nusa-php" + phpTag(version) + "@" + user + ".service"
}

func (m *Manager) home(user string) string {
	return m.cfg.HomeRoot + "/" + user
}

func (m *Manager) phpFPMBinary(version string) string {
	return m.cfg.PHPFPMDir + "/php-fpm" + version
}

func (m *Manager) poolVersionDir(version string) string {
	return m.cfg.PoolDir + "/" + version
}

func (m *Manager) poolFile(user, version string) string {
	return m.poolVersionDir(version) + "/" + user + ".conf"
}

func (m *Manager) unitTemplate(version string) string {
	return m.cfg.UnitDir + "/nusa-php" + phpTag(version) + "@.service"
}

func (m *Manager) nginxAccountDir(user string) string {
	return m.cfg.NginxDir + "/" + user
}

func (m *Manager) apacheAccountDir(user string) string {
	return m.cfg.ApacheDir + "/" + user
}

func (m *Manager) nginxVhost(user, domain string) string {
	return m.nginxAccountDir(user) + "/" + domain + ".conf"
}

func (m *Manager) apacheVhost(user, domain string) string {
	return m.apacheAccountDir(user) + "/" + domain + ".conf"
}

func (m *Manager) suspendedFile(user string) string {
	return m.nginxAccountDir(user) + "/suspended.inc"
}

// removedDomainsFile lists the domains of an account whose vhosts
// RemoveAccount removed but whose certificates may still exist. It has no
// .conf suffix, so neither web server ever loads it.
func (m *Manager) removedDomainsFile(user string) string {
	return m.nginxAccountDir(user) + "/.removed-domains"
}

// certificateDir is the folder certbot keeps the current certificate of
// domain in, as symlinks into its archive.
func (m *Manager) certificateDir(domain string) string {
	return m.cfg.LetsEncryptDir + "/live/" + domain
}

func (m *Manager) certificateFile(domain string) string {
	return m.certificateDir(domain) + "/fullchain.pem"
}

func (m *Manager) certificateKeyFile(domain string) string {
	return m.certificateDir(domain) + "/privkey.pem"
}

// renewalFile is certbot's renewal configuration of the certificate named
// domain; it exists exactly as long as certbot manages that certificate.
func (m *Manager) renewalFile(domain string) string {
	return m.cfg.LetsEncryptDir + "/renewal/" + domain + ".conf"
}

func (m *Manager) challengeDir() string {
	return m.cfg.AcmeDir + "/.well-known/acme-challenge"
}
