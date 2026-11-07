package actions

import (
	"context"
	"encoding/json"
	"time"
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
