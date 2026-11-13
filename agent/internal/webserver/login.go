package webserver

import (
	"context"
	"errors"
	"fmt"
	"path/filepath"
	"strings"
	"time"

	"rayxo/agent/internal/validate"
)

// A reseller's own sign-in address: the panel served on ports 80 and 443
// of a domain of the reseller's, with that domain's Let's Encrypt
// certificate. Its site includes the panel's own configuration, so the
// panel runs there exactly as on its own port, straight to PHP-FPM:
// visitors' addresses stay what the panel sees.

// loginRenewBefore is how long before it expires a login domain's
// certificate is issued again when asked; certbot's timer renews it before
// that anyway.
const loginRenewBefore = 30 * 24 * time.Hour

// ErrDomainTaken means a login domain is the server's hostname or a
// website's domain, or a website's domain is a login domain.
var ErrDomainTaken = errors.New("the domain is used by the server or a website")

// LoginDomain is a login domain that serves the panel.
type LoginDomain struct {
	Domain    string    `json:"domain"`
	ExpiresAt time.Time `json:"expires_at"`
}

// loginLineage is certbot's name for a login domain's certificate. The
// underscore, which no website's domain has, keeps it apart from the
// certificate of a website of the same name.
func loginLineage(domain string) string {
	return "rayxo-login_" + domain
}

func (m *Manager) loginSite(domain string) string {
	return filepath.Join(m.cfg.SitesDir, "rayxo-login-"+domain+".conf")
}

// HasLoginDomain reports whether domain is a login domain, which no
// website may then have.
func (m *Manager) HasLoginDomain(domain string) bool {
	return exists(m.loginSite(domain))
}

// ApplyLoginDomain makes the panel answer at domain: its certificate is
// issued over HTTP-01 unless a valid one exists (so domain must reach this
// server over HTTP, else *UnreachableError), then its site is written and
// Nginx reloaded. A domain the server's hostname or a website has fails
// with ErrDomainTaken. certbot failures are *IssueError or ErrCertbotBusy.
func (m *Manager) ApplyLoginDomain(ctx context.Context, domain string) (LoginDomain, error) {
	if err := m.checkLoginDomain(domain); err != nil {
		return LoginDomain{}, err
	}

	if !exists(m.cfg.PanelSnippet) {
		return LoginDomain{}, fmt.Errorf("the panel's Nginx configuration %s is missing; run setup.sh again", m.cfg.PanelSnippet)
	}

	lineage := loginLineage(domain)
	certificate, err := readFirstCertificate(m.certificateFile(lineage))

	if err != nil || certificate.VerifyHostname(domain) != nil || certificate.NotAfter.Sub(m.now()) < loginRenewBefore {
		if err := m.checkReaches(ctx, domain); err != nil {
			return LoginDomain{}, err
		}

		if err := m.runCertbot(ctx, domain, []string{domain}, certbotIssueArgs(m.cfg.AcmeDir, lineage, []string{domain})); err != nil {
			return LoginDomain{}, err
		}

		if certificate, err = readFirstCertificate(m.certificateFile(lineage)); err != nil {
			return LoginDomain{}, err
		}
	}

	content, err := render("nginx-login.conf.tmpl", struct {
		Domain, AcmeDir, Certificate, CertificateKey, PanelSnippet string
		Listen                                                     []string
	}{
		Domain:         domain,
		AcmeDir:        m.cfg.AcmeDir,
		Certificate:    m.certificateFile(lineage),
		CertificateKey: m.certificateKeyFile(lineage),
		PanelSnippet:   m.cfg.PanelSnippet,
		Listen:         m.listenAddresses(Site{}),
	})
	if err != nil {
		return LoginDomain{}, err
	}

	m.mu.Lock()
	defer m.mu.Unlock()

	if err := ensureDir(m.cfg.SitesDir, 0o755); err != nil {
		return LoginDomain{}, err
	}

	if err := m.commit(ctx, []fileChange{{path: m.loginSite(domain), content: content}}, nginxService); err != nil {
		return LoginDomain{}, err
	}

	return LoginDomain{Domain: domain, ExpiresAt: certificate.NotAfter.UTC()}, nil
}

// RemoveLoginDomain stops the panel answering at domain and deletes its
// certificate. One that does not exist is not an error.
func (m *Manager) RemoveLoginDomain(ctx context.Context, domain string) error {
	if err := validate.Domain(domain); err != nil {
		return err
	}

	m.mu.Lock()
	err := m.commit(ctx, []fileChange{{path: m.loginSite(domain)}}, nginxService)
	m.mu.Unlock()

	if err != nil {
		return err
	}

	return m.deleteCertificate(ctx, loginLineage(domain))
}

// checkLoginDomain refuses a name that is not a domain, the server's
// hostname or one of its names, or a website's domain.
func (m *Manager) checkLoginDomain(domain string) error {
	if err := validate.Domain(domain); err != nil {
		return err
	}

	for _, name := range m.cfg.Hostnames() {
		if domain == name || strings.HasSuffix(domain, "."+name) {
			return fmt.Errorf("%w: %s is the server's own name", ErrDomainTaken, domain)
		}
	}

	website, err := m.hasVhost(domain)
	if err != nil {
		return err
	}

	if website {
		return fmt.Errorf("%w: %s is a website", ErrDomainTaken, domain)
	}

	return nil
}
