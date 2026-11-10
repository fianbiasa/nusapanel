package actions

import (
	"context"
	"encoding/json"
	"errors"
	"time"

	"rayxo/agent/internal/validate"
)

// certificateInfo is the result of ssl.issue.
type certificateInfo struct {
	Names     []string `json:"names"`
	ExpiresAt string   `json:"expires_at"`
}

// issueCertificate implements ssl.issue: it gets a Let's Encrypt
// certificate for a domain of the account, and for www.<domain> when that
// reaches this server too. A valid certificate for the same names is kept,
// so calling it again returns the same result. It does not turn HTTPS on;
// web.apply_domain with ssl does.
func (w webActions) issueCertificate(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[domainParams](raw)
	if err != nil {
		return nil, err
	}

	user, err := w.checkDomain(ctx, params.Username, params.Domain)
	if err != nil {
		return nil, err
	}

	certificate, err := w.web.IssueCertificate(ctx, user.Name, params.Domain)
	if err != nil {
		return nil, webError(err, params.Domain)
	}

	return certificateInfo{
		Names:     certificate.Names,
		ExpiresAt: certificate.ExpiresAt.UTC().Format(time.RFC3339),
	}, nil
}

// installCertificateParams are the params of ssl.install. Certificate and
// PrivateKey are PEM; the key never reaches a log.
type installCertificateParams struct {
	Username    string `json:"username"`
	Domain      string `json:"domain"`
	Certificate string `json:"certificate"`
	PrivateKey  string `json:"private_key"`
}

func (p installCertificateParams) account() string { return p.Username }

func (p installCertificateParams) validate() error {
	if p.Certificate == "" || p.PrivateKey == "" {
		return errors.New("certificate and private_key are required")
	}

	return firstError(validate.Username(p.Username), validate.Domain(p.Domain))
}

// customCertificateInfo is the result of ssl.install.
type customCertificateInfo struct {
	Names     []string `json:"names"`
	ExpiresAt string   `json:"expires_at"`
	Issuer    string   `json:"issuer"`
	Trusted   bool     `json:"trusted"`
}

// installCertificate implements ssl.install: it checks a certificate the
// customer brings (with its intermediates) and its private key for a
// domain of the account, and installs them. It does not turn HTTPS on;
// web.apply_domain with ssl and certificate "custom" does. Installing again
// replaces the certificate.
func (w webActions) installCertificate(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[installCertificateParams](raw)
	if err != nil {
		return nil, err
	}

	user, err := w.checkDomain(ctx, params.Username, params.Domain)
	if err != nil {
		return nil, err
	}

	certificate, err := w.web.InstallCertificate(ctx, user.Name, params.Domain, params.Certificate, params.PrivateKey)
	if err != nil {
		return nil, webError(err, params.Domain)
	}

	return customCertificateInfo{
		Names:     certificate.Names,
		ExpiresAt: certificate.ExpiresAt.UTC().Format(time.RFC3339),
		Issuer:    certificate.Issuer,
		Trusted:   certificate.Trusted,
	}, nil
}

// deleteCertificate implements ssl.delete: it deletes the certificate of a
// domain of the account once its HTTPS is off. A domain without a
// certificate succeeds.
func (w webActions) deleteCertificate(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[domainParams](raw)
	if err != nil {
		return nil, err
	}

	user, err := w.checkDomain(ctx, params.Username, params.Domain)
	if err != nil {
		return nil, err
	}

	if err := w.web.DeleteCertificate(ctx, user.Name, params.Domain); err != nil {
		return nil, webError(err, params.Domain)
	}

	return struct{}{}, nil
}
