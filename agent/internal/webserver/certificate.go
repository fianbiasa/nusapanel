package webserver

import (
	"bytes"
	"context"
	"crypto/x509"
	"encoding/pem"
	"errors"
	"fmt"
	"os"
	"regexp"
	"strings"
	"sync"
	"time"

	"nusapanel/agent/internal/runner"
	"nusapanel/agent/internal/validate"
)

// Customer domains get Let's Encrypt certificates from certbot, through the
// webroot every port 80 vhost serves (Config.AcmeDir). Each certificate is
// named after its domain (--cert-name) and covers the domain and, when it
// reaches this server too, www.<domain>. certbot.timer renews them through
// the same webroot; the deploy hook setup.sh installs reloads Nginx.

var (
	// ErrDomainNotSetUp means the account has no vhost for the domain.
	ErrDomainNotSetUp = errors.New("the domain is not set up on this account")
	// ErrAccountSuspended means the account's websites answer with the
	// suspended page, Let's Encrypt challenges included.
	ErrAccountSuspended = errors.New("the account is suspended")
	// ErrVhostOutdated means the domain's vhost was written before vhosts
	// served Let's Encrypt challenges; applying the domain again fixes it.
	ErrVhostOutdated = errors.New("the vhost does not serve Let's Encrypt challenges")
	// ErrCertificateInUse means the domain's vhost still serves HTTPS with
	// the certificate.
	ErrCertificateInUse = errors.New("the vhost still uses the certificate")
	// ErrCertbotBusy means another certbot process, such as a renewal run
	// by certbot.timer, held certbot's lock.
	ErrCertbotBusy = errors.New("another certbot instance is running")
)

// UnreachableError means a domain does not reach this server over HTTP, so
// Let's Encrypt could not verify it either.
type UnreachableError struct {
	Domain string
	// Reason says why in a few words a panel user can act on, such as
	// "no DNS record".
	Reason string
}

func (e *UnreachableError) Error() string {
	return e.Domain + " does not reach this server over HTTP: " + e.Reason
}

// IssueError means certbot did not get a certificate.
type IssueError struct {
	Domain string
	// RateLimited is set when Let's Encrypt refused because of a rate limit.
	RateLimited bool
	// Detail is Let's Encrypt's own explanation, short and safe to show to
	// panel users. It may be empty.
	Detail string
}

func (e *IssueError) Error() string {
	message := "certbot could not issue a certificate for " + e.Domain
	if e.RateLimited {
		message += " (rate limited)"
	}

	if e.Detail != "" {
		message += ": " + e.Detail
	}

	return message
}

// Certificate is a domain's certificate as issued.
type Certificate struct {
	// Names are the host names it covers.
	Names []string
	// ExpiresAt is when it stops being valid, in UTC.
	ExpiresAt time.Time
}

// certbotMu serializes every certbot run of this process: certbot runs only
// once at a time and fails right away instead of waiting.
var certbotMu sync.Mutex

const (
	// certbotLockMessage is what certbot prints when another certbot holds
	// its lock.
	certbotLockMessage = "Another instance of Certbot is already running"
	// maxDetailLength limits the Let's Encrypt explanation shown to users.
	maxDetailLength = 300
)

// acmeErrorPattern finds an ACME error certbot printed, such as
// "urn:ietf:params:acme:error:rateLimited :: ... :: too many certificates".
var acmeErrorPattern = regexp.MustCompile(`urn:ietf:params:acme:error:([A-Za-z]+)[^\n]*`)

// HasCertificate reports whether domain has a certificate and its key.
func (m *Manager) HasCertificate(domain string) bool {
	if validate.Domain(domain) != nil {
		return false
	}

	for _, path := range []string{m.certificateFile(domain), m.certificateKeyFile(domain)} {
		info, err := os.Stat(path)
		if err != nil || !info.Mode().IsRegular() {
			return false
		}
	}

	return true
}

// IssueCertificate gets a Let's Encrypt certificate for domain of user, or
// renews it when the names changed or it expires soon; a valid certificate
// for the same names is kept. domain must reach this server over HTTP (else
// *UnreachableError); www.<domain> is included only when it does too.
// certbot failures are returned as *IssueError or ErrCertbotBusy.
func (m *Manager) IssueCertificate(ctx context.Context, user, domain string) (Certificate, error) {
	if err := checkNames(user, domain); err != nil {
		return Certificate{}, err
	}

	vhost, err := m.accountVhost(user, domain)
	if err != nil {
		return Certificate{}, err
	}

	if exists(m.suspendedFile(user)) {
		return Certificate{}, fmt.Errorf("%w: %s", ErrAccountSuspended, user)
	}

	if !m.servesChallenges(vhost) {
		return Certificate{}, fmt.Errorf("%w: %s", ErrVhostOutdated, domain)
	}

	names, err := m.reachableNames(ctx, domain)
	if err != nil {
		return Certificate{}, err
	}

	if err := m.runCertbotIssue(ctx, domain, names); err != nil {
		return Certificate{}, err
	}

	expiresAt, err := certificateExpiry(m.certificateFile(domain))
	if err != nil {
		return Certificate{}, err
	}

	// A vhost already serving HTTPS only picks a new certificate up when
	// Nginx reloads.
	if m.usesCertificate(vhost, domain) {
		if err := m.reloadNginx(ctx); err != nil {
			return Certificate{}, err
		}
	}

	return Certificate{Names: names, ExpiresAt: expiresAt}, nil
}

// DeleteCertificate deletes the certificate of domain of user, unless the
// domain's vhost still serves HTTPS with it (ErrCertificateInUse). A
// domain without a certificate succeeds.
func (m *Manager) DeleteCertificate(ctx context.Context, user, domain string) error {
	if err := checkNames(user, domain); err != nil {
		return err
	}

	vhost, err := m.accountVhost(user, domain)
	if err != nil {
		return err
	}

	if m.usesCertificate(vhost, domain) {
		return fmt.Errorf("%w: %s", ErrCertificateInUse, domain)
	}

	return m.deleteCertificate(ctx, domain)
}

// deleteUnusedCertificates deletes the certificates of domains whose
// vhosts were removed. A domain some account has a vhost for again keeps
// its certificate: it belongs to that website now.
func (m *Manager) deleteUnusedCertificates(ctx context.Context, domains []string) error {
	for _, domain := range domains {
		used, err := m.hasVhost(domain)
		if err != nil {
			return err
		}

		if used {
			m.logger().Info("certificate kept: a website uses the domain", "domain", domain)

			continue
		}

		if err := m.deleteCertificate(ctx, domain); err != nil {
			return err
		}
	}

	return nil
}

// deleteCertificate has certbot delete the certificate named domain. A
// certificate certbot does not know is not an error.
func (m *Manager) deleteCertificate(ctx context.Context, domain string) error {
	certbotMu.Lock()
	defer certbotMu.Unlock()

	if !exists(m.renewalFile(domain)) {
		return nil
	}

	output, err := m.cfg.Run(ctx, certbotProgram, "delete", "--cert-name", domain, "--non-interactive")
	if err != nil {
		m.logger().Error("certbot could not delete a certificate", "domain", domain, "output", string(output), "error", err)

		if strings.Contains(string(output), certbotLockMessage) {
			return fmt.Errorf("%w: deleting the certificate of %s", ErrCertbotBusy, domain)
		}

		return fmt.Errorf("delete the certificate of %s: %w", domain, err)
	}

	m.logger().Info("certificate deleted", "domain", domain)

	return nil
}

// runCertbotIssue has certbot get a certificate for names, named domain.
func (m *Manager) runCertbotIssue(ctx context.Context, domain string, names []string) error {
	certbotMu.Lock()
	defer certbotMu.Unlock()

	output, err := m.cfg.Run(ctx, certbotProgram, certbotIssueArgs(m.cfg.AcmeDir, domain, names)...)
	if err == nil {
		m.logger().Info("certificate issued or still valid", "domain", domain, "names", names)

		return nil
	}

	m.logger().Error("certbot could not issue a certificate",
		"domain", domain, "names", names, "output", string(output), "error", err)

	if runner.ExitCode(err) < 0 && ctx.Err() == nil {
		// certbot did not run at all.
		return fmt.Errorf("run certbot: %w", err)
	}

	return certbotIssueFailure(domain, string(output))
}

// certbotIssueArgs returns the certbot arguments that get a certificate
// named domain for names. With --cert-name, a different set of names
// replaces the certificate's names (certbot's confirmation defaults to yes
// with --non-interactive), so no --expand is needed; the same names keep a
// certificate that is not due for renewal (--keep-until-expiring). The
// existing ACME account is used; only when there is none, one is
// registered without an email address.
func certbotIssueArgs(acmeDir, domain string, names []string) []string {
	args := []string{"certonly", "--webroot", "-w", acmeDir, "--cert-name", domain}
	for _, name := range names {
		args = append(args, "-d", name)
	}

	return append(args, "--non-interactive", "--agree-tos", "--keep-until-expiring", "--register-unsafely-without-email")
}

// certbotIssueFailure turns the output of a failed certbot run into an
// error whose fields are safe to show to panel users.
func certbotIssueFailure(domain, output string) error {
	if strings.Contains(output, certbotLockMessage) {
		return fmt.Errorf("%w: issuing a certificate for %s", ErrCertbotBusy, domain)
	}

	acmeType, acmeDetail := acmeError(output)
	if acmeType == "rateLimited" || strings.Contains(output, "letsencrypt.org/docs/rate-limits") {
		return &IssueError{Domain: domain, RateLimited: true, Detail: acmeDetail}
	}

	if detail := challengeDetail(output); detail != "" {
		return &IssueError{Domain: domain, Detail: detail}
	}

	return &IssueError{Domain: domain, Detail: acmeDetail}
}

// challengeDetail returns the first "Detail:" line certbot prints for a
// challenge Let's Encrypt could not verify.
func challengeDetail(output string) string {
	for line := range strings.Lines(output) {
		if detail, ok := strings.CutPrefix(strings.TrimSpace(line), "Detail:"); ok {
			return shortDetail(detail)
		}
	}

	return ""
}

// acmeError returns the type and the most specific part of the first ACME
// error certbot printed, as in "<type> :: <description> :: <detail>".
func acmeError(output string) (errorType, detail string) {
	match := acmeErrorPattern.FindStringSubmatch(output)
	if match == nil {
		return "", ""
	}

	parts := strings.Split(match[0], " :: ")
	if len(parts) < 2 {
		return match[1], ""
	}

	return match[1], shortDetail(parts[len(parts)-1])
}

// shortDetail trims detail, drops the documentation link Let's Encrypt
// appends and limits its length.
func shortDetail(detail string) string {
	detail, _, _ = strings.Cut(detail, ": see http")
	detail = strings.TrimSpace(detail)

	if len(detail) > maxDetailLength {
		detail = strings.ToValidUTF8(detail[:maxDetailLength], "") + "..."
	}

	return detail
}

// certificateExpiry returns when the first certificate in the PEM file at
// path expires, in UTC.
func certificateExpiry(path string) (time.Time, error) {
	content, err := os.ReadFile(path)
	if err != nil {
		return time.Time{}, fmt.Errorf("read %s: %w", path, err)
	}

	block, _ := pem.Decode(content)
	if block == nil || block.Type != "CERTIFICATE" {
		return time.Time{}, fmt.Errorf("%s holds no certificate", path)
	}

	certificate, err := x509.ParseCertificate(block.Bytes)
	if err != nil {
		return time.Time{}, fmt.Errorf("parse %s: %w", path, err)
	}

	return certificate.NotAfter.UTC(), nil
}

// accountVhost returns the Nginx vhost of domain of user, or
// ErrDomainNotSetUp when there is none.
func (m *Manager) accountVhost(user, domain string) ([]byte, error) {
	path := m.nginxVhost(user, domain)

	content, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return nil, fmt.Errorf("%w: %s", ErrDomainNotSetUp, domain)
	}

	if err != nil {
		return nil, fmt.Errorf("read %s: %w", path, err)
	}

	return content, nil
}

// servesChallenges reports whether vhost answers Let's Encrypt challenges
// from AcmeDir.
func (m *Manager) servesChallenges(vhost []byte) bool {
	return bytes.Contains(vhost, []byte("location ^~ /.well-known/acme-challenge/ {")) &&
		bytes.Contains(vhost, []byte("root "+m.cfg.AcmeDir+";"))
}

// usesCertificate reports whether vhost serves HTTPS with the certificate
// of domain.
func (m *Manager) usesCertificate(vhost []byte, domain string) bool {
	return bytes.Contains(vhost, []byte(m.certificateDir(domain)+"/"))
}

// hasVhost reports whether any account has an Nginx vhost for domain.
func (m *Manager) hasVhost(domain string) (bool, error) {
	entries, err := os.ReadDir(m.cfg.NginxDir)
	if errors.Is(err, os.ErrNotExist) {
		return false, nil
	}

	if err != nil {
		return false, fmt.Errorf("list %s: %w", m.cfg.NginxDir, err)
	}

	for _, entry := range entries {
		if !entry.IsDir() {
			continue
		}

		_, err := os.Lstat(m.nginxVhost(entry.Name(), domain))
		if err == nil {
			return true, nil
		}

		if !errors.Is(err, os.ErrNotExist) {
			return false, err
		}
	}

	return false, nil
}

// reloadNginx tests the configuration and reloads Nginx.
func (m *Manager) reloadNginx(ctx context.Context) error {
	m.mu.Lock()
	defer m.mu.Unlock()

	return m.commit(ctx, nil, nginxService)
}
