package webserver

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/x509"
	"encoding/hex"
	"encoding/pem"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	"rayxo/agent/internal/validate"
)

// The server's own certificate. The panel (port 2222), phpMyAdmin and FTP
// use the Let's Encrypt certificate of the server's hostname, which
// setup.sh gets when it installs. When Let's Encrypt fails then, the panel
// runs on a self-signed certificate until the hostname certificate is
// issued again, from the panel or by running setup.sh again.

// ErrNoServerHostname means the server has no hostname a certificate can
// be issued for: setup.sh names it, and the name must be a domain.
var ErrNoServerHostname = errors.New("the server has no fully qualified hostname")

// HostnameCertificate describes the certificate the panel serves.
type HostnameCertificate struct {
	Hostname string `json:"hostname"`
	// Names are the names the certificate covers.
	Names     []string  `json:"names"`
	Issuer    string    `json:"issuer"`
	ExpiresAt time.Time `json:"expires_at"`
	// LetsEncrypt is set when the panel serves the hostname's Let's
	// Encrypt certificate, which certbot.timer renews, rather than a
	// self-signed one.
	LetsEncrypt bool `json:"lets_encrypt"`
}

// ServerHostname is the name setup.sh gave the server: the most qualified
// of its names, so the FQDN rather than a short kernel hostname.
func (m *Manager) ServerHostname() (string, error) {
	var hostname string

	for _, name := range m.cfg.Hostnames() {
		if strings.Count(name, ".") > strings.Count(hostname, ".") {
			hostname = name
		}
	}

	if validate.Domain(hostname) != nil {
		return "", fmt.Errorf("%w: %q", ErrNoServerHostname, hostname)
	}

	return hostname, nil
}

// HasWebsite reports whether an account has a website for domain, which
// the server's hostname may then not become.
func (m *Manager) HasWebsite(domain string) (bool, error) {
	return m.hasVhost(domain)
}

// HostnameCertificateStatus reads the certificate the panel serves.
func (m *Manager) HostnameCertificateStatus() (HostnameCertificate, error) {
	hostname, err := m.ServerHostname()
	if err != nil {
		return HostnameCertificate{}, err
	}

	certificate, err := readFirstCertificate(m.panelCertificateFile())
	if err != nil {
		return HostnameCertificate{}, err
	}

	issuer := certificate.Issuer.CommonName
	if len(certificate.Issuer.Organization) > 0 {
		issuer = certificate.Issuer.Organization[0]
	}

	link, _ := os.Readlink(m.panelCertificateFile())

	return HostnameCertificate{
		Hostname:    hostname,
		Names:       certificate.DNSNames,
		Issuer:      issuer,
		ExpiresAt:   certificate.NotAfter.UTC(),
		LetsEncrypt: link == m.hostnameCertificateFile(hostname, "fullchain.pem"),
	}, nil
}

// IssueHostnameCertificate gets the Let's Encrypt certificate of the
// server's hostname (a valid one is kept, as certbot.timer renews it) and
// makes the panel, phpMyAdmin and FTP serve it. certbot failures are
// *IssueError or ErrCertbotBusy, as for websites.
//
// Unlike a website's, the hostname is not checked first: many servers map
// their own name to a loopback address in /etc/hosts, so the check would
// fail on a hostname whose public DNS is right. Let's Encrypt checks it
// from outside, the way setup.sh relies on.
func (m *Manager) IssueHostnameCertificate(ctx context.Context) (HostnameCertificate, error) {
	hostname, err := m.ServerHostname()
	if err != nil {
		return HostnameCertificate{}, err
	}

	if err := m.runCertbot(ctx, hostname, []string{hostname}, certbotIssueArgs(m.cfg.AcmeDir, hostname, []string{hostname})); err != nil {
		return HostnameCertificate{}, err
	}

	if err := replaceSymlink(m.hostnameCertificateFile(hostname, "fullchain.pem"), m.panelCertificateFile()); err != nil {
		return HostnameCertificate{}, err
	}

	if err := replaceSymlink(m.hostnameCertificateFile(hostname, "privkey.pem"), m.panelKeyFile()); err != nil {
		return HostnameCertificate{}, err
	}

	if err := m.reloadNginx(ctx); err != nil {
		return HostnameCertificate{}, err
	}

	if err := m.updateFTPCertificate(ctx, hostname); err != nil {
		return HostnameCertificate{}, err
	}

	m.logger().Info("hostname certificate in use", "hostname", hostname)

	return m.HostnameCertificateStatus()
}

// updateFTPCertificate gives Pure-FTPd the hostname certificate, which it
// reads from one file holding key and certificate, and only at start. A
// server without FTP set up (no such file yet) is left alone, and Pure-FTPd
// is only restarted when the certificate changed, since a restart cuts
// running transfers.
func (m *Manager) updateFTPCertificate(ctx context.Context, hostname string) error {
	current, err := os.ReadFile(m.cfg.FTPCertificate)
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}

	if err != nil {
		return fmt.Errorf("read %s: %w", m.cfg.FTPCertificate, err)
	}

	key, err := os.ReadFile(m.hostnameCertificateFile(hostname, "privkey.pem"))
	if err != nil {
		return fmt.Errorf("read the hostname key: %w", err)
	}

	chain, err := os.ReadFile(m.hostnameCertificateFile(hostname, "fullchain.pem"))
	if err != nil {
		return fmt.Errorf("read the hostname certificate: %w", err)
	}

	combined := append(key, chain...)
	if bytes.Equal(current, combined) {
		return nil
	}

	if err := writeFileAtomic(m.cfg.FTPCertificate, combined, 0o600, nil); err != nil {
		return err
	}

	if _, err := m.cfg.Run(ctx, systemctlProgram, "try-restart", m.cfg.FTPUnit); err != nil {
		return fmt.Errorf("restart %s: %w", m.cfg.FTPUnit, err)
	}

	return nil
}

func (m *Manager) panelCertificateFile() string {
	return filepath.Join(m.cfg.PanelCertificateDir, "panel.crt")
}

func (m *Manager) panelKeyFile() string {
	return filepath.Join(m.cfg.PanelCertificateDir, "panel.key")
}

func (m *Manager) hostnameCertificateFile(hostname, name string) string {
	return filepath.Join(m.cfg.LetsEncryptDir, "live", hostname, name)
}

// readFirstCertificate parses the first certificate in the PEM file at path.
func readFirstCertificate(path string) (*x509.Certificate, error) {
	content, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("read %s: %w", path, err)
	}

	block, _ := pem.Decode(content)
	if block == nil || block.Type != "CERTIFICATE" {
		return nil, fmt.Errorf("%s holds no certificate", path)
	}

	certificate, err := x509.ParseCertificate(block.Bytes)
	if err != nil {
		return nil, fmt.Errorf("parse %s: %w", path, err)
	}

	return certificate, nil
}

// replaceSymlink points link at target in one step, also when link is a
// regular file now (a self-signed certificate setup.sh made).
func replaceSymlink(target, link string) error {
	suffix := make([]byte, 6)
	if _, err := rand.Read(suffix); err != nil {
		return err
	}

	temporary := link + ".tmp-" + hex.EncodeToString(suffix)
	if err := os.Symlink(target, temporary); err != nil {
		return fmt.Errorf("link %s: %w", link, err)
	}

	if err := os.Rename(temporary, link); err != nil {
		_ = os.Remove(temporary)

		return fmt.Errorf("link %s: %w", link, err)
	}

	return nil
}
