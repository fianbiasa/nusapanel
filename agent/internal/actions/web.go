package actions

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"time"

	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/sysuser"
	"rayxo/agent/internal/validate"
	"rayxo/agent/internal/webserver"
)

// webServer is what the web.*, php.* and ssl.* actions need.
// *webserver.Manager implements it.
type webServer interface {
	PHPInstalled(version string) bool
	PHPVersions() ([]string, error)
	IsHostname(domain string) bool
	UsedByOtherAccount(domain, user string) (bool, error)
	ApplyDomain(ctx context.Context, site webserver.Site) (documentRoot string, err error)
	RemoveDomain(ctx context.Context, user, domain string) error
	RemovePool(ctx context.Context, user, version string) error
	IssueCertificate(ctx context.Context, user, domain string) (webserver.Certificate, error)
	IssueWildcardCertificate(ctx context.Context, user, domain string, nameservers []string) (webserver.Certificate, error)
	InstallCertificate(ctx context.Context, user, domain, certificatePEM, keyPEM string) (webserver.CustomCertificate, error)
	DeleteCertificate(ctx context.Context, user, domain string) error
	CertificateExpiry(domain string) (expiresAt time.Time, ok bool)
}

// applyDomainParams are the params of web.apply_domain. Everything after
// php_version is optional, so callers from before those features existed
// keep working: a missing kind is "domain", which also serves subdomains.
type applyDomainParams struct {
	Username   string `json:"username"`
	Domain     string `json:"domain"`
	PHPVersion string `json:"php_version"`
	SSL        bool   `json:"ssl"`
	ForceHTTPS bool   `json:"force_https"`
	// WWW is "both" (also when missing), "www" or "bare": which of the
	// domain's two names visitors end up on.
	WWW string `json:"www"`
	// NoPHP turns PHP off for the website.
	NoPHP bool `json:"no_php"`
	// Root is the folder the website is served from, relative to the home;
	// empty means its own domains/<domain>/public_html.
	Root string `json:"root"`
	// Certificate says which certificate serves HTTPS: "letsencrypt" (the
	// default), "custom" (one installed with ssl.install) or "parent" (the
	// wildcard certificate of CertificateDomain, a domain one label up).
	Certificate       string `json:"certificate"`
	CertificateDomain string `json:"certificate_domain"`
	// Kind is "domain", "alias" (a pointer showing the website of parent)
	// or "forward" (a pointer redirecting to parent).
	Kind      string           `json:"kind"`
	Parent    string           `json:"parent"`
	Redirects []redirectParams `json:"redirects"`
	// PHPSettings are the website's php.ini settings that differ from the
	// defaults, such as {"memory_limit": "512M"}.
	PHPSettings map[string]string `json:"php_settings"`
	// ErrorPages are the HTTP statuses the website answers with its own
	// page, <document root>/.errors/<status>.html.
	ErrorPages []int `json:"error_pages"`
	// ProtectedDirectories ask for a login before their files are served;
	// an alias carries those of the website it shows.
	ProtectedDirectories []protectedDirectoryParams `json:"protected_directories"`
	// Hotlink keeps other websites from embedding files; null is off.
	Hotlink *hotlinkParams `json:"hotlink"`
	// MIMETypes override the content type of file extensions.
	MIMETypes []mimeTypeParams `json:"mime_types"`
	// PHPExtensions also run as PHP, on top of .php and .phtml.
	PHPExtensions []string `json:"php_extensions"`
	// CGI runs the scripts in cgi-bin as the account.
	CGI bool `json:"cgi"`
	// Suspended shows the unavailable page instead of the website, which
	// reached a limit of its own.
	Suspended bool `json:"suspended"`
}

// protectedDirectoryParams are one protected directory of
// web.apply_domain.
type protectedDirectoryParams struct {
	Path  string                `json:"path"`
	Realm string                `json:"realm"`
	Users []protectedUserParams `json:"users"`
}

// protectedUserParams are one login of a protected directory, with the
// SHA-512 crypt hash of its password.
type protectedUserParams struct {
	Name string `json:"name"`
	Hash string `json:"hash"`
}

// hotlinkParams are the hotlink protection of web.apply_domain.
type hotlinkParams struct {
	Extensions  []string `json:"extensions"`
	Domains     []string `json:"domains"`
	AllowDirect bool     `json:"allow_direct"`
	RedirectTo  string   `json:"redirect_to"`
}

// mimeTypeParams are one content type of web.apply_domain.
type mimeTypeParams struct {
	Extension string `json:"extension"`
	Type      string `json:"type"`
}

// redirectParams are one URL redirect of web.apply_domain.
type redirectParams struct {
	Path        string `json:"path"`
	Destination string `json:"destination"`
	Status      int    `json:"status"`
	Wildcard    bool   `json:"wildcard"`
}

func (p applyDomainParams) account() string { return p.Username }

func (p applyDomainParams) validate() error {
	if p.ForceHTTPS && !p.SSL {
		return errors.New("force_https needs ssl")
	}

	switch p.Certificate {
	case "", "letsencrypt", "custom":
		if p.CertificateDomain != "" {
			return errors.New("certificate_domain only goes with certificate parent")
		}
	case "parent":
		if p.CertificateDomain == "" {
			return errors.New("certificate parent needs certificate_domain")
		}
	default:
		return fmt.Errorf("certificate %q must be letsencrypt, custom or parent", p.Certificate)
	}

	return p.site(sysuser.User{Name: p.Username}).Validate()
}

// site returns the website the params describe, for the account user.
func (p applyDomainParams) site(user sysuser.User) webserver.Site {
	redirects := make([]webserver.Redirect, len(p.Redirects))
	for i, redirect := range p.Redirects {
		redirects[i] = webserver.Redirect(redirect)
	}

	directories := make([]webserver.ProtectedDirectory, len(p.ProtectedDirectories))
	for i, directory := range p.ProtectedDirectories {
		users := make([]webserver.ProtectedUser, len(directory.Users))
		for j, login := range directory.Users {
			users[j] = webserver.ProtectedUser(login)
		}

		directories[i] = webserver.ProtectedDirectory{Path: directory.Path, Realm: directory.Realm, Users: users}
	}

	var hotlink *webserver.Hotlink
	if p.Hotlink != nil {
		converted := webserver.Hotlink(*p.Hotlink)
		hotlink = &converted
	}

	mimeTypes := make([]webserver.MIMEType, len(p.MIMETypes))
	for i, mime := range p.MIMETypes {
		mimeTypes[i] = webserver.MIMEType(mime)
	}

	return webserver.Site{
		User:                 user.Name,
		UID:                  user.UID,
		GID:                  user.GID,
		Domain:               p.Domain,
		PHPVersion:           p.PHPVersion,
		SSL:                  p.SSL,
		ForceHTTPS:           p.ForceHTTPS,
		WWW:                  webserver.WWWMode(p.WWW),
		NoPHP:                p.NoPHP,
		Root:                 p.Root,
		CustomCertificate:    p.Certificate == "custom",
		CertificateDomain:    p.CertificateDomain,
		Kind:                 webserver.Kind(p.Kind),
		Parent:               p.Parent,
		Redirects:            redirects,
		PHPSettings:          p.PHPSettings,
		ErrorPages:           p.ErrorPages,
		ProtectedDirectories: directories,
		Hotlink:              hotlink,
		MIMETypes:            mimeTypes,
		PHPExtensions:        p.PHPExtensions,
		CGI:                  p.CGI,
		Suspended:            p.Suspended,
	}
}

// domainParams are the params of web.remove_domain, ssl.issue and
// ssl.delete.
type domainParams struct {
	Username string `json:"username"`
	Domain   string `json:"domain"`
}

func (p domainParams) account() string { return p.Username }

func (p domainParams) validate() error {
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

// webActions implements the web.*, php.* and ssl.* methods.
type webActions struct {
	accounts accountActions
	web      webServer
}

// webMethods returns the web.*, php.* and ssl.* methods by name. They lock
// the account like the account.* methods, so no two calls change one
// account at the same time.
func webMethods(system accountSystem, web webServer) map[string]rpc.Method {
	actions := webActions{accounts: accountActions{system: system}, web: web}

	return map[string]rpc.Method{
		"web.apply_domain":  {Handler: actions.applyDomain, LockKey: webLockKey[applyDomainParams]},
		"web.remove_domain": {Handler: actions.removeDomain, LockKey: webLockKey[domainParams]},
		"php.remove_pool":   {Handler: actions.removePool, LockKey: webLockKey[removePoolParams]},
		"php.versions":      {Handler: actions.phpVersions},
		"ssl.issue":         {Handler: actions.issueCertificate, LockKey: webLockKey[issueCertificateParams]},
		"ssl.install":       {Handler: actions.installCertificate, LockKey: webLockKey[installCertificateParams]},
		"ssl.delete":        {Handler: actions.deleteCertificate, LockKey: webLockKey[domainParams]},
		"ssl.expiry":        {Handler: actions.reportExpiry},
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
// Nginx and Apache vhosts, with HTTPS when ssl is set (the certificate must
// exist), HTTP redirected to HTTPS when force_https is set, and the given
// URL redirects. An alias serves the folder of its parent; a forward
// redirects to its parent and has neither folder nor pool. Calling it
// again renders the same configuration.
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

	documentRoot, err := w.web.ApplyDomain(ctx, params.site(user))
	if err != nil {
		return nil, webError(err, params.Domain)
	}

	return domainInfo{Domain: params.Domain, DocumentRoot: documentRoot}, nil
}

// removeDomain implements web.remove_domain: it removes the domain's Nginx
// and Apache vhosts, then its logs and certificate. The domain's files stay
// in the home. Removing a domain without configuration succeeds.
func (w webActions) removeDomain(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[domainParams](raw)
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

// phpVersionsInfo is the result of php.versions.
type phpVersionsInfo struct {
	Versions []string `json:"versions"`
}

// phpVersions implements php.versions: the installed PHP versions websites
// can use, oldest first. It changes nothing, so it locks nothing.
func (w webActions) phpVersions(_ context.Context, raw json.RawMessage) (any, error) {
	if err := rpc.DecodeParams(raw, &struct{}{}); err != nil {
		return nil, err
	}

	versions, err := w.web.PHPVersions()
	if err != nil {
		return nil, err
	}

	return phpVersionsInfo{Versions: versions}, nil
}

// checkDomain refuses the server's own hostname and returns the rayxo
// account username names.
func (w webActions) checkDomain(ctx context.Context, username, domain string) (sysuser.User, error) {
	if w.web.IsHostname(domain) {
		return sysuser.User{}, rpc.Errorf(rpc.CodeActionFailed, "domain %s is the hostname of this server", domain)
	}

	return w.existingAccount(ctx, username)
}

// existingAccount returns the rayxo account username names, failing
// when it does not exist or is not managed by rayxo.
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
	if unreachable, ok := errors.AsType[*webserver.UnreachableError](err); ok {
		return rpc.Errorf(rpc.CodeActionFailed,
			"%s does not reach this server over HTTP (%s); point its A record to this server, "+
				"or proxy it through Cloudflare, then try again", unreachable.Domain, unreachable.Reason)
	}

	if issue, ok := errors.AsType[*webserver.IssueError](err); ok {
		return issueError(issue)
	}

	if notDelegated, ok := errors.AsType[*webserver.NotDelegatedError](err); ok {
		return rpc.Errorf(rpc.CodeActionFailed,
			"%s; a wildcard certificate needs the domain to use this server's nameservers: "+
				"change them at the domain's registrar, wait until they are in use, then try again", notDelegated.Error())
	}

	if refused, ok := errors.AsType[*webserver.CertificateError](err); ok {
		return rpc.Errorf(rpc.CodeActionFailed, "%s", refused.Reason)
	}

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
	case errors.Is(err, webserver.ErrNoCertificate):
		return rpc.Errorf(rpc.CodeActionFailed, "there is no certificate for %s yet; request one first", subject)
	case errors.Is(err, webserver.ErrDomainNotSetUp):
		return rpc.Errorf(rpc.CodeActionFailed, "%s is not set up on this account", subject)
	case errors.Is(err, webserver.ErrCertificateInUse):
		return rpc.Errorf(rpc.CodeActionFailed, "turn off HTTPS for %s before deleting its certificate", subject)
	case errors.Is(err, webserver.ErrAccountSuspended):
		return rpc.Errorf(rpc.CodeActionFailed, "%s cannot get a certificate while its account is suspended", subject)
	case errors.Is(err, webserver.ErrVhostOutdated):
		return rpc.Errorf(rpc.CodeActionFailed,
			"the web server configuration of %s is from an older version; apply the domain again, then request the certificate", subject)
	case errors.Is(err, webserver.ErrCertbotBusy):
		return rpc.Errorf(rpc.CodeActionFailed, "another certificate operation is running; try again in a minute")
	}

	return err
}

// issueError explains why Let's Encrypt did not issue a certificate.
func issueError(issue *webserver.IssueError) error {
	switch {
	case issue.RateLimited && issue.Detail != "":
		return rpc.Errorf(rpc.CodeActionFailed,
			"Let's Encrypt rate limit reached for %s (%s); try again later", issue.Domain, issue.Detail)
	case issue.RateLimited:
		return rpc.Errorf(rpc.CodeActionFailed, "Let's Encrypt rate limit reached for %s; try again later", issue.Domain)
	case issue.Detail != "":
		return rpc.Errorf(rpc.CodeActionFailed, "Let's Encrypt could not issue a certificate for %s: %s", issue.Domain, issue.Detail)
	}

	return rpc.Errorf(rpc.CodeActionFailed,
		"Let's Encrypt could not issue a certificate for %s; the agent log has the details", issue.Domain)
}
