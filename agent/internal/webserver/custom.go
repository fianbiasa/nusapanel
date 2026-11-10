package webserver

import (
	"bytes"
	"context"
	"crypto"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rsa"
	"crypto/x509"
	"encoding/pem"
	"fmt"
	"os"
	"strings"
	"time"

	"rayxo/agent/internal/validate"
)

// A customer can also bring a certificate of their own, such as one bought
// from a CA or a Cloudflare Origin Certificate. It lives in
// Config.CustomCertificateDir/<domain>/ (root only, /etc/rayxo/ssl/domains
// on a server) and is used by the
// domain's vhost when web.apply_domain asks for it. Nothing renews it.

const (
	maxCertificateBytes = 64 << 10
	maxKeyBytes         = 16 << 10
	maxChainLength      = 10
)

// CertificateError means a custom certificate was refused. Reason is safe
// to show to panel users and never contains key material.
type CertificateError struct {
	Reason string
}

func (e *CertificateError) Error() string {
	return "the certificate cannot be used: " + e.Reason
}

func certificateError(format string, args ...any) error {
	return &CertificateError{Reason: fmt.Sprintf(format, args...)}
}

// CustomCertificate describes a custom certificate as installed.
type CustomCertificate struct {
	// Names are the host names it covers, wildcards included.
	Names []string
	// ExpiresAt is when it stops being valid, in UTC.
	ExpiresAt time.Time
	// Issuer is who signed it, such as "R11" or "Cloudflare Origin SSL
	// Certificate Authority".
	Issuer string
	// Trusted reports whether browsers accept it: whether it chains up to a
	// root this server trusts with the intermediates given. A Cloudflare
	// Origin Certificate is not, and is still fine behind Cloudflare.
	Trusted bool
}

// parsedCertificate is a custom certificate that passed every check, in
// the form it is written to disk.
type parsedCertificate struct {
	info  CustomCertificate
	chain []byte
	key   []byte
}

// InstallCertificate checks a custom certificate (PEM, the domain's own
// certificate first, then any intermediates) and its private key (PEM, not
// encrypted) for domain of user, and installs them. They must belong
// together, cover domain, be valid now, and use an RSA key of at least 2048
// bits or an ECDSA key on P-256, P-384 or P-521. Refusals are
// *CertificateError. Installing does not touch the vhost: web.apply_domain
// with the custom certificate does.
func (m *Manager) InstallCertificate(_ context.Context, user, domain, certificatePEM, keyPEM string) (CustomCertificate, error) {
	if err := checkNames(user, domain); err != nil {
		return CustomCertificate{}, err
	}

	if _, err := m.accountVhost(user, domain); err != nil {
		return CustomCertificate{}, err
	}

	parsed, err := parseCustomCertificate(domain, certificatePEM, keyPEM, m.now(), m.cfg.CertificateRoots)
	if err != nil {
		return CustomCertificate{}, err
	}

	for _, dir := range []string{m.cfg.CustomCertificateDir, m.customCertificateDir(domain)} {
		if err := ensureDir(dir, 0o700); err != nil {
			return CustomCertificate{}, err
		}
	}

	// The key first: a certificate without its key is never in place.
	if err := writeFileAtomic(m.customCertificateKeyFile(domain), parsed.key, 0o600, nil); err != nil {
		return CustomCertificate{}, err
	}

	if err := writeFileAtomic(m.customCertificateFile(domain), parsed.chain, 0o644, nil); err != nil {
		return CustomCertificate{}, err
	}

	m.logger().Info("custom certificate installed", "domain", domain, "user", user,
		"issuer", parsed.info.Issuer, "expires_at", parsed.info.ExpiresAt)

	return parsed.info, nil
}

// HasCustomCertificate reports whether domain has a custom certificate and
// its key.
func (m *Manager) HasCustomCertificate(domain string) bool {
	if validate.Domain(domain) != nil {
		return false
	}

	for _, path := range []string{m.customCertificateFile(domain), m.customCertificateKeyFile(domain)} {
		info, err := os.Stat(path)
		if err != nil || !info.Mode().IsRegular() {
			return false
		}
	}

	return true
}

// deleteCustomCertificate removes the custom certificate of domain, if any.
func (m *Manager) deleteCustomCertificate(domain string) error {
	dir := m.customCertificateDir(domain)
	if !exists(dir) {
		return nil
	}

	if err := os.RemoveAll(dir); err != nil {
		return fmt.Errorf("remove %s: %w", dir, err)
	}

	m.logger().Info("custom certificate deleted", "domain", domain)

	return nil
}

// usesCustomCertificate reports whether vhost serves HTTPS with the custom
// certificate of domain.
func (m *Manager) usesCustomCertificate(vhost []byte, domain string) bool {
	return bytes.Contains(vhost, []byte("ssl_certificate "+m.customCertificateFile(domain)+";"))
}

func (m *Manager) now() time.Time {
	if m.cfg.Now != nil {
		return m.cfg.Now()
	}

	return time.Now()
}

// parseCustomCertificate checks a certificate chain and key for domain at
// time now, and returns them re-encoded: only the certificates, and the
// key as PKCS #8, so nothing else that came with the PEM reaches the disk.
// roots are the trusted roots; nil means the system's.
func parseCustomCertificate(domain, certificatePEM, keyPEM string, now time.Time, roots *x509.CertPool) (parsedCertificate, error) {
	chain, err := parseCertificateChain(certificatePEM)
	if err != nil {
		return parsedCertificate{}, err
	}

	key, err := parsePrivateKey(keyPEM)
	if err != nil {
		return parsedCertificate{}, err
	}

	leaf := chain[0]

	public, ok := leaf.PublicKey.(interface{ Equal(crypto.PublicKey) bool })
	if !ok || !public.Equal(key.Public()) {
		return parsedCertificate{}, certificateError("the private key does not belong to the certificate")
	}

	if err := leaf.VerifyHostname(domain); err != nil {
		return parsedCertificate{}, certificateError("the certificate does not cover %s", domain)
	}

	if now.Before(leaf.NotBefore) {
		return parsedCertificate{}, certificateError("the certificate is only valid from %s", leaf.NotBefore.UTC().Format(time.DateOnly))
	}

	if now.After(leaf.NotAfter) {
		return parsedCertificate{}, certificateError("the certificate expired on %s", leaf.NotAfter.UTC().Format(time.DateOnly))
	}

	intermediates := x509.NewCertPool()
	for _, certificate := range chain[1:] {
		intermediates.AddCert(certificate)
	}

	_, verifyErr := leaf.Verify(x509.VerifyOptions{
		DNSName:       domain,
		Intermediates: intermediates,
		Roots:         roots,
		CurrentTime:   now,
	})

	keyDER, err := x509.MarshalPKCS8PrivateKey(key)
	if err != nil {
		return parsedCertificate{}, certificateError("the private key cannot be stored")
	}

	var encoded bytes.Buffer
	for _, certificate := range chain {
		if err := pem.Encode(&encoded, &pem.Block{Type: "CERTIFICATE", Bytes: certificate.Raw}); err != nil {
			return parsedCertificate{}, fmt.Errorf("encode the certificate chain: %w", err)
		}
	}

	return parsedCertificate{
		info: CustomCertificate{
			Names:     certificateNames(leaf),
			ExpiresAt: leaf.NotAfter.UTC(),
			Issuer:    issuerName(leaf),
			Trusted:   verifyErr == nil,
		},
		chain: encoded.Bytes(),
		key:   pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: keyDER}),
	}, nil
}

// parseCertificateChain parses the PEM certificates in content, which must
// hold nothing else.
func parseCertificateChain(content string) ([]*x509.Certificate, error) {
	if len(content) > maxCertificateBytes {
		return nil, certificateError("the certificate is larger than %d KB", maxCertificateBytes>>10)
	}

	var chain []*x509.Certificate

	rest := []byte(strings.TrimSpace(content))

	for len(rest) > 0 {
		block, remaining := pem.Decode(rest)
		if block == nil {
			return nil, certificateError("the certificate is not in PEM format (-----BEGIN CERTIFICATE-----)")
		}

		if block.Type != "CERTIFICATE" {
			return nil, certificateError("the certificate field may only hold certificates, not a %s", strings.ToLower(block.Type))
		}

		certificate, err := x509.ParseCertificate(block.Bytes)
		if err != nil {
			return nil, certificateError("certificate %d cannot be read", len(chain)+1)
		}

		chain = append(chain, certificate)
		rest = bytes.TrimSpace(remaining)
	}

	if len(chain) == 0 {
		return nil, certificateError("there is no certificate")
	}

	if len(chain) > maxChainLength {
		return nil, certificateError("the chain has more than %d certificates", maxChainLength)
	}

	return chain, nil
}

// parsePrivateKey parses the one PEM private key in content.
func parsePrivateKey(content string) (crypto.Signer, error) {
	if len(content) > maxKeyBytes {
		return nil, certificateError("the private key is larger than %d KB", maxKeyBytes>>10)
	}

	block, rest := pem.Decode([]byte(strings.TrimSpace(content)))
	if block == nil {
		return nil, certificateError("the private key is not in PEM format (-----BEGIN PRIVATE KEY-----)")
	}

	if len(bytes.TrimSpace(rest)) > 0 {
		return nil, certificateError("the private key field may only hold one key")
	}

	if block.Type == "ENCRYPTED PRIVATE KEY" || strings.Contains(block.Headers["Proc-Type"], "ENCRYPTED") {
		return nil, certificateError("the private key is protected by a password; remove the password first")
	}

	var (
		key any
		err error
	)

	switch block.Type {
	case "PRIVATE KEY":
		key, err = x509.ParsePKCS8PrivateKey(block.Bytes)
	case "RSA PRIVATE KEY":
		key, err = x509.ParsePKCS1PrivateKey(block.Bytes)
	case "EC PRIVATE KEY":
		key, err = x509.ParseECPrivateKey(block.Bytes)
	default:
		return nil, certificateError("the private key field holds a %s, not a private key", strings.ToLower(block.Type))
	}

	if err != nil {
		return nil, certificateError("the private key cannot be read")
	}

	switch key := key.(type) {
	case *rsa.PrivateKey:
		if key.N.BitLen() < 2048 {
			return nil, certificateError("the RSA key has %d bits; at least 2048 are needed", key.N.BitLen())
		}

		return key, nil
	case *ecdsa.PrivateKey:
		switch key.Curve {
		case elliptic.P256(), elliptic.P384(), elliptic.P521():
			return key, nil
		}

		return nil, certificateError("the ECDSA key must use P-256, P-384 or P-521")
	}

	return nil, certificateError("only RSA and ECDSA keys are supported")
}

// certificateNames returns the host names certificate covers.
func certificateNames(certificate *x509.Certificate) []string {
	if len(certificate.DNSNames) > 0 {
		return certificate.DNSNames
	}

	return []string{certificate.Subject.CommonName}
}

// issuerName returns a short name of who signed certificate.
func issuerName(certificate *x509.Certificate) string {
	issuer := certificate.Issuer

	switch {
	case issuer.CommonName != "":
		return issuer.CommonName
	case len(issuer.Organization) > 0:
		return issuer.Organization[0]
	}

	return issuer.String()
}
