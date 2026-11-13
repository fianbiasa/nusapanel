package actions

import (
	"context"
	"encoding/json"
	"errors"
	"time"

	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/validate"
	"rayxo/agent/internal/webserver"
)

// loginDomains serves the panel at resellers' own domains.
// *webserver.Manager implements it.
type loginDomains interface {
	ApplyLoginDomain(ctx context.Context, domain string) (webserver.LoginDomain, error)
	RemoveLoginDomain(ctx context.Context, domain string) error
}

// loginDomainParams are the params of panel.add_login_domain and
// panel.remove_login_domain.
type loginDomainParams struct {
	Domain string `json:"domain"`
}

type loginActions struct {
	domains loginDomains
}

// loginMethods returns the panel.*_login_domain methods. Calls for the
// same domain run one at a time.
func loginMethods(domains loginDomains) map[string]rpc.Method {
	l := loginActions{domains: domains}
	lock := func(raw json.RawMessage) (string, error) {
		params, err := decodeLoginDomainParams(raw)
		if err != nil {
			return "", err
		}

		return "login:" + params.Domain, nil
	}

	return map[string]rpc.Method{
		"panel.add_login_domain":    {Handler: l.add, LockKey: lock},
		"panel.remove_login_domain": {Handler: l.remove, LockKey: lock},
	}
}

func decodeLoginDomainParams(raw json.RawMessage) (loginDomainParams, error) {
	var params loginDomainParams
	if err := rpc.DecodeParams(raw, &params); err != nil {
		return params, err
	}

	if err := validate.Domain(params.Domain); err != nil {
		return params, rpc.Errorf(rpc.CodeInvalidParams, "invalid params: %v", err)
	}

	return params, nil
}

// add implements panel.add_login_domain: the panel answers at the domain
// over HTTPS with its own Let's Encrypt certificate.
func (l loginActions) add(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeLoginDomainParams(raw)
	if err != nil {
		return nil, err
	}

	login, err := l.domains.ApplyLoginDomain(ctx, params.Domain)
	if errors.Is(err, webserver.ErrDomainTaken) {
		return nil, rpc.Errorf(rpc.CodeActionFailed, "%s is the server's own name or a website's domain", params.Domain)
	}

	if unreachable, ok := errors.AsType[*webserver.UnreachableError](err); ok {
		return nil, rpc.Errorf(rpc.CodeActionFailed,
			"%s does not reach this server over HTTP (%s); point its A record to this server, "+
				"without a proxy such as Cloudflare's, then try again", unreachable.Domain, unreachable.Reason)
	}

	if err != nil {
		return nil, webError(err, params.Domain)
	}

	return struct {
		Domain    string    `json:"domain"`
		ExpiresAt time.Time `json:"expires_at"`
	}{Domain: login.Domain, ExpiresAt: login.ExpiresAt}, nil
}

// remove implements panel.remove_login_domain.
func (l loginActions) remove(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeLoginDomainParams(raw)
	if err != nil {
		return nil, err
	}

	if err := l.domains.RemoveLoginDomain(ctx, params.Domain); err != nil {
		return nil, webError(err, params.Domain)
	}

	return struct{}{}, nil
}
