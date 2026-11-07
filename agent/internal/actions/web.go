package actions

import (
	"context"
	"encoding/json"
	"errors"

	"nusapanel/agent/internal/rpc"
	"nusapanel/agent/internal/sysuser"
	"nusapanel/agent/internal/validate"
	"nusapanel/agent/internal/webserver"
)

// webServer is what the web.* and php.* actions need. *webserver.Manager
// implements it.
type webServer interface {
	PHPInstalled(version string) bool
	IsHostname(domain string) bool
	UsedByOtherAccount(domain, user string) (bool, error)
	ApplyDomain(ctx context.Context, site webserver.Site) (documentRoot string, err error)
	RemoveDomain(ctx context.Context, user, domain string) error
	RemovePool(ctx context.Context, user, version string) error
}

// applyDomainParams are the params of web.apply_domain.
type applyDomainParams struct {
	Username   string `json:"username"`
	Domain     string `json:"domain"`
	PHPVersion string `json:"php_version"`
}

func (p applyDomainParams) account() string { return p.Username }

func (p applyDomainParams) validate() error {
	return firstError(validate.Username(p.Username), validate.Domain(p.Domain), validate.PHPVersion(p.PHPVersion))
}

// removeDomainParams are the params of web.remove_domain.
type removeDomainParams struct {
	Username string `json:"username"`
	Domain   string `json:"domain"`
}

func (p removeDomainParams) account() string { return p.Username }

func (p removeDomainParams) validate() error {
	return firstError(validate.Username(p.Username), validate.Domain(p.Domain))
}

// removePoolParams are the params of php.remove_pool.
type removePoolParams struct {
	Username   string `json:"username"`
	PHPVersion string `json:"php_version"`
}

func (p removePoolParams) account() string { return p.Username }

func (p removePoolParams) validate() error {
	return firstError(validate.Username(p.Username), validate.PHPVersion(p.PHPVersion))
}

// firstError returns the first error that is not nil.
func firstError(errs ...error) error {
	for _, err := range errs {
		if err != nil {
			return err
		}
	}

	return nil
}

// webParams are params that name one account.
type webParams interface {
	account() string
	validate() error
}

// domainInfo is the result of web.apply_domain.
type domainInfo struct {
	Domain       string `json:"domain"`
	DocumentRoot string `json:"document_root"`
}

// webActions implements the web.* and php.* methods.
type webActions struct {
	accounts accountActions
	web      webServer
}

// webMethods returns the web.* and php.* methods by name. They lock the
// account like the account.* methods, so no two calls change one account
// at the same time.
func webMethods(system accountSystem, web webServer) map[string]rpc.Method {
	actions := webActions{accounts: accountActions{system: system}, web: web}

	return map[string]rpc.Method{
		"web.apply_domain":  {Handler: actions.applyDomain, LockKey: webLockKey[applyDomainParams]},
		"web.remove_domain": {Handler: actions.removeDomain, LockKey: webLockKey[removeDomainParams]},
		"php.remove_pool":   {Handler: actions.removePool, LockKey: webLockKey[removePoolParams]},
	}
}

// webLockKey locks the account named in params.
func webLockKey[P webParams](raw json.RawMessage) (string, error) {
	params, err := decodeWebParams[P](raw)
	if err != nil {
		return "", err
	}

	return "account:" + params.account(), nil
}

// decodeWebParams strictly decodes and validates params.
func decodeWebParams[P webParams](raw json.RawMessage) (P, error) {
	var params P
	if err := rpc.DecodeParams(raw, &params); err != nil {
		return params, err
	}

	if err := params.validate(); err != nil {
		return params, rpc.Errorf(rpc.CodeInvalidParams, "invalid params: %v", err)
	}

	return params, nil
}

// applyDomain implements web.apply_domain: it creates the domain's folders,
// the account's PHP-FPM pool for the requested version, and the domain's
// Nginx and Apache vhosts. Calling it again renders the same configuration.
func (w webActions) applyDomain(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[applyDomainParams](raw)
	if err != nil {
		return nil, err
	}

	user, err := w.checkDomain(ctx, params.Username, params.Domain)
	if err != nil {
		return nil, err
	}

	if err := w.checkPHP(params.PHPVersion); err != nil {
		return nil, err
	}

	if err := w.checkNotUsedElsewhere(params.Domain, user.Name); err != nil {
		return nil, err
	}

	documentRoot, err := w.web.ApplyDomain(ctx, webserver.Site{
		User:       user.Name,
		UID:        user.UID,
		GID:        user.GID,
		Domain:     params.Domain,
		PHPVersion: params.PHPVersion,
	})
	if err != nil {
		return nil, webError(err, params.Domain)
	}

	return domainInfo{Domain: params.Domain, DocumentRoot: documentRoot}, nil
}

// removeDomain implements web.remove_domain: it removes the domain's Nginx
// and Apache vhosts. The domain's files stay in the home. Removing a domain
// without configuration succeeds.
func (w webActions) removeDomain(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[removeDomainParams](raw)
	if err != nil {
		return nil, err
	}

	user, err := w.checkDomain(ctx, params.Username, params.Domain)
	if err != nil {
		return nil, err
	}

	if err := w.checkNotUsedElsewhere(params.Domain, user.Name); err != nil {
		return nil, err
	}

	if err := w.web.RemoveDomain(ctx, user.Name, params.Domain); err != nil {
		return nil, webError(err, params.Domain)
	}

	return struct{}{}, nil
}

// removePool implements php.remove_pool: it stops, disables and deletes the
// account's pool for one PHP version. A missing pool succeeds, and so does
// a pool a vhost of the account still uses, which is kept.
func (w webActions) removePool(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[removePoolParams](raw)
	if err != nil {
		return nil, err
	}

	user, err := w.existingAccount(ctx, params.Username)
	if err != nil {
		return nil, err
	}

	if err := w.checkPHP(params.PHPVersion); err != nil {
		return nil, err
	}

	if err := w.web.RemovePool(ctx, user.Name, params.PHPVersion); err != nil {
		return nil, webError(err, "account "+user.Name)
	}

	return struct{}{}, nil
}

// checkDomain refuses the server's own hostname and returns the nusapanel
// account username names.
func (w webActions) checkDomain(ctx context.Context, username, domain string) (sysuser.User, error) {
	if w.web.IsHostname(domain) {
		return sysuser.User{}, rpc.Errorf(rpc.CodeActionFailed, "domain %s is the hostname of this server", domain)
	}

	return w.existingAccount(ctx, username)
}

// existingAccount returns the nusapanel account username names, failing
// when it does not exist or is not managed by nusapanel.
func (w webActions) existingAccount(ctx context.Context, username string) (sysuser.User, error) {
	user, found, err := w.accounts.findAccount(ctx, username)
	if err == nil && !found {
		err = rpc.Errorf(rpc.CodeActionFailed, "account %s does not exist", username)
	}

	return user, err
}

// checkPHP fails unless PHP-FPM of version is installed.
func (w webActions) checkPHP(version string) error {
	if !w.web.PHPInstalled(version) {
		return rpc.Errorf(rpc.CodeActionFailed, "PHP %s is not installed", version)
	}

	return nil
}

// checkNotUsedElsewhere fails when another account already serves domain.
func (w webActions) checkNotUsedElsewhere(domain, user string) error {
	used, err := w.web.UsedByOtherAccount(domain, user)
	if err != nil {
		return err
	}

	if used {
		return rpc.Errorf(rpc.CodeActionFailed, "domain %s is already used by another account", domain)
	}

	return nil
}

// webError turns the web server failures panel users can act on into
// readable messages about subject (a domain, or "account <name>"). Anything
// else stays an internal error whose details only reach the agent log.
func webError(err error, subject string) error {
	switch {
	case errors.Is(err, errors.ErrUnsupported):
		return rpc.Errorf(rpc.CodeActionFailed, "websites can only be managed on Linux")
	case errors.Is(err, webserver.ErrConfigRejected):
		return rpc.Errorf(rpc.CodeActionFailed, "the web server rejected the configuration for %s", subject)
	case errors.Is(err, webserver.ErrPoolRejected):
		return rpc.Errorf(rpc.CodeActionFailed, "PHP-FPM rejected the pool configuration for %s", subject)
	case errors.Is(err, sysuser.ErrInTheWay):
		return rpc.Errorf(rpc.CodeActionFailed,
			"the folder of %s cannot be created: a file or symlink is in the way inside the home directory", subject)
	}

	return err
}
