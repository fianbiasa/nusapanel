package webserver

import (
	"bytes"
	"crypto/sha256"
	"embed"
	"encoding/hex"
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
	// Listen are the addresses the site's servers listen on, each followed
	// by the port: "" (every IPv4 address), "[::]:" (every IPv6 address),
	// or "192.0.2.10:" and "[2001:db8::10]:" for its own.
	Listen []string
	// PHP is set when the website runs PHP, in the PHP-FPM pool Socket and
	// PoolName identify.
	PHP      bool
	Socket   string
	PoolName string
	// ClientMaxBodySize is the largest request body Nginx accepts, enough
	// for the website's POST limit.
	ClientMaxBodySize string
	// AcmeDir is the webroot of Let's Encrypt challenges.
	AcmeDir string
	// SSL adds a server on port 443 with Certificate and CertificateKey;
	// ForceHTTPS redirects port 80 to it.
	SSL        bool
	ForceHTTPS bool
	// WWW is "www" or "bare" when one of the site's two names redirects
	// to the other, else empty.
	WWW            string
	Certificate    string
	CertificateKey string
	// ForwardTo is the domain a forward redirects every request to; empty
	// for a site that serves a folder.
	ForwardTo string
	// Redirects are the site's URL redirects, in the order Nginx checks
	// them.
	Redirects []redirectData
	// ErrorPages are the site's own error pages, by status.
	ErrorPages []errorPageData
	// Protected are the folders that ask for a login.
	Protected []protectedData
	// Hotlink, when set, refuses other websites' requests for some files.
	Hotlink *hotlinkData
	// StaticPattern matches the files Nginx serves from disk, and
	// StaticTypes are the content types the site sets for some of them.
	StaticPattern string
	StaticTypes   []mimeData
	// MIMETypes are every content type the site sets, for Apache.
	MIMETypes []mimeData
	// PHPPattern matches the files Apache runs as PHP.
	PHPPattern string
	// CGI runs the scripts in cgi-bin as User.
	CGI bool
	// Suspended answers every request with the unavailable page.
	Suspended bool
}

// staticData is what the template of the static files location sees.
type staticData struct {
	// Indent goes before every line, for a location nested in another.
	Indent  string
	Pattern string
	Types   []mimeData
}

// Static is the static files location of the site, indented by indent.
func (d siteData) Static(indent string) staticData {
	return staticData{Indent: indent, Pattern: d.StaticPattern, Types: d.StaticTypes}
}

// poolData is what the pool section template sees: the PHP-FPM pool of
// one website, or the single pool of a whole account from before websites
// had pools of their own.
type poolData struct {
	User       string
	PHPVersion string
	PHPTag     string
	Home       string
	// Section names the pool: the website's domain (the username for the
	// old pool of the account).
	Section  string
	Socket   string
	PoolName string
	Settings []phpSetting
	// LimitExtensions are the only files the pool runs: .php, .phtml and
	// the website's own PHP extensions.
	LimitExtensions string
}

// masterData is what the account's main PHP-FPM configuration of one
// version sees.
type masterData struct {
	User       string
	PHPVersion string
	PHPTag     string
	RunDir     string
	// Name is the master's pid file and syslog name, php84-budi.
	Name string
	// SectionDir holds the pool section of every website of the account on
	// this version.
	SectionDir string
	// Include loads them; empty in the configuration PHP-FPM checks, which
	// has them inline.
	Include string
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
	pool := m.sitePoolData(site)

	var forwardTo string
	if site.kind() == KindForward {
		forwardTo = site.Parent
	}

	certificate, certificateKey := m.certificateFile(site.Domain), m.certificateKeyFile(site.Domain)
	if site.CertificateDomain != "" {
		certificate, certificateKey = m.certificateFile(site.CertificateDomain), m.certificateKeyFile(site.CertificateDomain)
	}

	if site.CustomCertificate {
		certificate, certificateKey = m.customCertificateFile(site.Domain), m.customCertificateKeyFile(site.Domain)
	}

	documentRoot := m.siteDocumentRoot(site)

	return siteData{
		User:              site.User,
		Domain:            site.Domain,
		DocumentRoot:      documentRoot,
		ForwardTo:         forwardTo,
		Redirects:         redirectsData(site.Redirects),
		LogDir:            m.cfg.LogDir,
		NginxAccountDir:   m.nginxAccountDir(site.User),
		Backend:           m.cfg.Backend,
		Listen:            m.listenAddresses(site),
		PHP:               !site.NoPHP,
		Socket:            pool.Socket,
		PoolName:          pool.PoolName,
		ClientMaxBodySize: clientMaxBodySize(site.PHPSettings),
		AcmeDir:           m.cfg.AcmeDir,
		SSL:               site.SSL,
		ForceHTTPS:        site.ForceHTTPS,
		WWW:               wwwRedirect(site.WWW),
		Certificate:       certificate,
		CertificateKey:    certificateKey,
		ErrorPages:        errorPagesData(site.ErrorPages),
		Protected:         m.protectedData(site, documentRoot),
		Hotlink:           hotlinkTemplateData(site.Domain, site.Hotlink),
		StaticPattern:     strings.Join(StaticExtensions, "|"),
		StaticTypes:       mimeTypesData(site.MIMETypes, isStaticExtension),
		MIMETypes:         mimeTypesData(site.MIMETypes, nil),
		PHPPattern:        phpPattern(site.PHPExtensions),
		CGI:               site.CGI,
		Suspended:         site.Suspended,
	}
}

// sitePoolData returns the pool of site: named after its domain, with a
// socket named after a hash of it, so even the longest domain fits in a
// socket path.
func (m *Manager) sitePoolData(site Site) poolData {
	name := m.masterName(site.User, site.PHPVersion) + "-" + siteKey(site.Domain)

	return poolData{
		User:       site.User,
		PHPVersion: site.PHPVersion,
		PHPTag:     phpTag(site.PHPVersion),
		Home:       m.home(site.User),
		Section:    site.Domain,
		Socket:     m.cfg.RunDir + "/" + name + ".sock",
		PoolName:   name,
		Settings:   phpSettingsData(site.PHPSettings),
		// Apache hands the pool exactly these files (phpPattern).
		LimitExtensions: limitExtensions(site.PHPExtensions),
	}
}

// legacyPoolData returns the single pool every website of user on version
// used before websites had pools of their own. It is kept, with the
// default settings, while an Apache vhost of user still sends PHP to it.
func (m *Manager) legacyPoolData(user, version string) poolData {
	name := m.masterName(user, version)

	return poolData{
		User:            user,
		PHPVersion:      version,
		PHPTag:          phpTag(version),
		Home:            m.home(user),
		Section:         user,
		Socket:          m.cfg.RunDir + "/" + name + ".sock",
		PoolName:        name,
		Settings:        phpSettingsData(nil),
		LimitExtensions: limitExtensions(nil),
	}
}

func (m *Manager) masterData(user, version string, include bool) masterData {
	data := masterData{
		User:       user,
		PHPVersion: version,
		PHPTag:     phpTag(version),
		RunDir:     m.cfg.RunDir,
		Name:       m.masterName(user, version),
		SectionDir: m.poolSectionDir(user, version),
	}

	if include {
		data.Include = data.SectionDir + "/*.conf"
	}

	return data
}

// masterName names the PHP-FPM master of user for version, php84-budi.
func (m *Manager) masterName(user, version string) string {
	return "php" + phpTag(version) + "-" + user
}

// siteKey is a short, fixed-length name for domain: the start of its
// SHA-256 in hex.
func siteKey(domain string) string {
	sum := sha256.Sum256([]byte(domain))

	return hex.EncodeToString(sum[:])[:12]
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

// listenAddresses returns where the site's servers listen (siteData.Listen):
// its own addresses, or every address of a version it has none of. IPv6
// is left out on a server without it.
func (m *Manager) listenAddresses(site Site) []string {
	listen := []string{""}
	if site.IPv4Address != "" {
		listen[0] = site.IPv4Address + ":"
	}

	switch {
	case site.IPv6Address != "":
		listen = append(listen, "["+site.IPv6Address+"]:")
	case m.hasIPv6():
		listen = append(listen, "[::]:")
	}

	return listen
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
	return "rayxo-php" + phpTag(version) + "@" + user + ".service"
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

// poolSectionDir holds the pool sections of the websites of user on
// version, one <domain>.conf each.
func (m *Manager) poolSectionDir(user, version string) string {
	return m.poolVersionDir(version) + "/" + user + ".d"
}

func (m *Manager) poolSectionFile(user, version, domain string) string {
	return m.poolSectionDir(user, version) + "/" + domain + ".conf"
}

func (m *Manager) unitTemplate(version string) string {
	return m.cfg.UnitDir + "/rayxo-php" + phpTag(version) + "@.service"
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

// customCertificateDir is the folder of the certificate the customer
// installed for domain.
func (m *Manager) customCertificateDir(domain string) string {
	return m.cfg.CustomCertificateDir + "/" + domain
}

func (m *Manager) customCertificateFile(domain string) string {
	return m.customCertificateDir(domain) + "/fullchain.pem"
}

func (m *Manager) customCertificateKeyFile(domain string) string {
	return m.customCertificateDir(domain) + "/privkey.pem"
}

func (m *Manager) challengeDir() string {
	return m.cfg.AcmeDir + "/.well-known/acme-challenge"
}

// wwwRedirect is the mode the vhost template redirects for: empty when the
// site serves both names.
func wwwRedirect(mode WWWMode) string {
	if mode == WWWOnly || mode == WWWBare {
		return string(mode)
	}

	return ""
}
