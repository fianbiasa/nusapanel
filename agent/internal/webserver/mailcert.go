package webserver

import (
	"context"
	"fmt"
	"time"

	"rayxo/agent/internal/validate"
)

// Mail certificate sources: where the certificate serving mail.<domain>
// comes from.
const (
	// MailSourceOwn is mail.<domain>'s own Let's Encrypt certificate.
	MailSourceOwn = "mail"
	// MailSourceCustom is the domain's custom certificate, which covers
	// mail.<domain> (a wildcard, or one that names it).
	MailSourceCustom = "custom"
	// MailSourceDomain is the domain's Let's Encrypt wildcard.
	MailSourceDomain = "domain"
)

// mailRenewBefore is how long before it expires mail.<domain>'s own
// certificate is issued again when asked; certbot's timer renews it before
// that anyway.
const mailRenewBefore = 30 * 24 * time.Hour

// MailCertificate is the certificate IMAP, POP3 and SMTP clients get when
// they ask for mail.<domain> (SNI).
type MailCertificate struct {
	Name            string    `json:"name"`
	Source          string    `json:"source"`
	ExpiresAt       time.Time `json:"expires_at"`
	CertificateFile string    `json:"-"`
	KeyFile         string    `json:"-"`
}

// mailLineage is certbot's name for mail.<domain>'s own certificate. The
// underscore, which no website's domain has, keeps it apart from the
// certificate of a website called mail.<domain>.
func mailLineage(domain string) string {
	return "rayxo-mail_" + domain
}

// FindMailCertificate returns a certificate valid now that covers
// mail.<domain>: its own, the domain's custom one, or the domain's Let's
// Encrypt one (a wildcard covers it), in that order; ErrNoCertificate when
// there is none.
func (m *Manager) FindMailCertificate(domain string) (MailCertificate, error) {
	name, err := mailName(domain)
	if err != nil {
		return MailCertificate{}, err
	}

	candidates := []struct{ source, certificate, key string }{
		{MailSourceOwn, m.certificateFile(mailLineage(domain)), m.certificateKeyFile(mailLineage(domain))},
		{MailSourceCustom, m.customCertificateFile(domain), m.customCertificateKeyFile(domain)},
		{MailSourceDomain, m.certificateFile(domain), m.certificateKeyFile(domain)},
	}

	for _, candidate := range candidates {
		certificate, err := readFirstCertificate(candidate.certificate)
		if err != nil || certificate.VerifyHostname(name) != nil || !m.now().Before(certificate.NotAfter) || !exists(candidate.key) {
			continue
		}

		return MailCertificate{
			Name:            name,
			Source:          candidate.source,
			ExpiresAt:       certificate.NotAfter.UTC(),
			CertificateFile: candidate.certificate,
			KeyFile:         candidate.key,
		}, nil
	}

	return MailCertificate{}, fmt.Errorf("%w: %s", ErrNoCertificate, name)
}

// IssueMailCertificate makes sure mail.<domain> has a certificate: one of
// the domain's that covers it (such as its wildcard) is used as it is, as
// is its own while it is not about to expire; otherwise Let's Encrypt
// issues it one over HTTP-01, answered by the server's default site. So
// mail.<domain> must reach this server over HTTP (else *UnreachableError).
// certbot failures are returned as *IssueError or ErrCertbotBusy.
func (m *Manager) IssueMailCertificate(ctx context.Context, domain string) (MailCertificate, error) {
	name, err := mailName(domain)
	if err != nil {
		return MailCertificate{}, err
	}

	found, err := m.FindMailCertificate(domain)
	if err == nil && (found.Source != MailSourceOwn || found.ExpiresAt.Sub(m.now()) > mailRenewBefore) {
		return found, nil
	}

	if err := m.checkReaches(ctx, name); err != nil {
		return MailCertificate{}, err
	}

	lineage := mailLineage(domain)
	if err := m.runCertbot(ctx, name, []string{name}, certbotIssueArgs(m.cfg.AcmeDir, lineage, []string{name})); err != nil {
		return MailCertificate{}, err
	}

	return m.FindMailCertificate(domain)
}

// DeleteMailCertificate deletes mail.<domain>'s own certificate; the
// domain's certificates stay. One that does not exist is not an error.
func (m *Manager) DeleteMailCertificate(ctx context.Context, domain string) error {
	if _, err := mailName(domain); err != nil {
		return err
	}

	return m.deleteCertificate(ctx, mailLineage(domain))
}

func mailName(domain string) (string, error) {
	if err := validate.Domain(domain); err != nil {
		return "", err
	}

	name := "mail." + domain
	if err := validate.Domain(name); err != nil {
		return "", err
	}

	return name, nil
}
