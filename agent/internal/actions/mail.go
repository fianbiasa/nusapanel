package actions

import (
	"context"
	"encoding/json"

	"rayxo/agent/internal/mail"
	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/sysuser"
	"rayxo/agent/internal/validate"
)

// mailServer is what the mail.* actions need from the server. mail.Manager
// implements it.
type mailServer interface {
	ApplyDomain(ctx context.Context, account sysuser.User, domain, selector string) (string, error)
	RemoveDomain(ctx context.Context, account *sysuser.User, domain string) error
	RemoveMailbox(ctx context.Context, account sysuser.User, domain, localPart string) error
	ApplySieve(ctx context.Context, account sysuser.User, domain, localPart, script string) error
	ApplySendLimits(limits mail.SendLimits) error
	Usage(ctx context.Context) ([]mail.MailboxUsage, error)
}

// mailDomainParams are the params of mail.domain_apply.
type mailDomainParams struct {
	Username string `json:"username"`
	Domain   string `json:"domain"`
	Selector string `json:"selector"`
}

func (p mailDomainParams) account() string { return p.Username }

func (p mailDomainParams) validate() error {
	return firstError(validate.Username(p.Username), validate.Domain(p.Domain), mail.ValidateSelector(p.Selector))
}

// mailboxParams are the params of mail.mailbox_remove.
type mailboxParams struct {
	Username  string `json:"username"`
	Domain    string `json:"domain"`
	LocalPart string `json:"local_part"`
}

func (p mailboxParams) account() string { return p.Username }

func (p mailboxParams) validate() error {
	return firstError(validate.Username(p.Username), validate.Domain(p.Domain), mail.ValidateLocalPart(p.LocalPart))
}

// mailSieveParams are the params of mail.sieve_apply.
type mailSieveParams struct {
	Username  string `json:"username"`
	Domain    string `json:"domain"`
	LocalPart string `json:"local_part"`
	Script    string `json:"script"`
}

func (p mailSieveParams) account() string { return p.Username }

func (p mailSieveParams) validate() error {
	return firstError(
		validate.Username(p.Username),
		validate.Domain(p.Domain),
		mail.ValidateLocalPart(p.LocalPart),
		mail.ValidateSieve(p.Script),
	)
}

// mailDomainResult is the result of mail.domain_apply.
type mailDomainResult struct {
	DKIMPublicKey string `json:"dkim_public_key"`
}

// mailActions implements the mail.* methods.
type mailActions struct {
	accounts accountActions
	server   mailServer
}

// mailMethods returns the mail.* methods by name. They lock the account
// like the web.* methods.
func mailMethods(system accountSystem, server mailServer) map[string]rpc.Method {
	actions := mailActions{accounts: accountActions{system: system}, server: server}

	return map[string]rpc.Method{
		"mail.domain_apply":   {Handler: actions.applyDomain, LockKey: webLockKey[mailDomainParams]},
		"mail.domain_remove":  {Handler: actions.removeDomain, LockKey: webLockKey[domainParams]},
		"mail.mailbox_remove": {Handler: actions.removeMailbox, LockKey: webLockKey[mailboxParams]},
		"mail.sieve_apply":    {Handler: actions.applySieve, LockKey: webLockKey[mailSieveParams]},
		// Only reads Dovecot's quota bookkeeping.
		"mail.usage": {Handler: func(ctx context.Context, raw json.RawMessage) (any, error) {
			if err := rpc.DecodeParams(raw, &struct{}{}); err != nil {
				return nil, err
			}

			usages, err := actions.server.Usage(ctx)
			if err != nil {
				return nil, rpc.Errorf(rpc.CodeActionFailed, "the mail usage could not be read: %v", err)
			}

			return map[string]any{"mailboxes": usages}, nil
		}},
		// One map for the whole server, written by one call at a time.
		"mail.send_limits_apply": {Handler: actions.applySendLimits, LockKey: func(json.RawMessage) (string, error) {
			return "mail:send_limits", nil
		}},
	}
}

// applySendLimits implements mail.send_limits_apply: the daily send limits
// and the accounts' domains go into the map Rspamd's ratelimit module reads.
func (m mailActions) applySendLimits(_ context.Context, raw json.RawMessage) (any, error) {
	var params mail.SendLimits
	if err := rpc.DecodeParams(raw, &params); err != nil {
		return nil, err
	}

	if err := params.Validate(); err != nil {
		return nil, rpc.Errorf(rpc.CodeInvalidParams, "invalid params: %v", err)
	}

	if err := m.server.ApplySendLimits(params); err != nil {
		return nil, rpc.Errorf(rpc.CodeActionFailed, "the send limits could not be saved: %v", err)
	}

	return struct{}{}, nil
}

// applySieve implements mail.sieve_apply: the mailbox's Sieve script (its
// filters and autoreply) is written in its folder as the account, or
// removed when empty.
func (m mailActions) applySieve(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[mailSieveParams](raw)
	if err != nil {
		return nil, err
	}

	account, err := m.accounts.requireAccount(ctx, params.Username)
	if err != nil {
		return nil, err
	}

	if err := m.server.ApplySieve(ctx, account, params.Domain, params.LocalPart, params.Script); err != nil {
		return nil, rpc.Errorf(rpc.CodeActionFailed, "the filters of %s@%s could not be saved: %v", params.LocalPart, params.Domain, err)
	}

	return struct{}{}, nil
}

// applyDomain implements mail.domain_apply: the folder of the domain's
// mailboxes in the account's home and its DKIM key, made when missing.
// It returns the public key for the domain's DKIM record.
func (m mailActions) applyDomain(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[mailDomainParams](raw)
	if err != nil {
		return nil, err
	}

	account, err := m.accounts.requireAccount(ctx, params.Username)
	if err != nil {
		return nil, err
	}

	key, err := m.server.ApplyDomain(ctx, account, params.Domain, params.Selector)
	if err != nil {
		return nil, rpc.Errorf(rpc.CodeActionFailed, "mail for %s could not be set up: %v", params.Domain, err)
	}

	return mailDomainResult{DKIMPublicKey: key}, nil
}

// removeDomain implements mail.domain_remove: the domain's DKIM keys go,
// and, while the account exists, the mail of all its mailboxes.
func (m mailActions) removeDomain(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[domainParams](raw)
	if err != nil {
		return nil, err
	}

	account, found, err := m.accounts.findAccount(ctx, params.Username)
	if err != nil {
		return nil, err
	}

	var owner *sysuser.User
	if found {
		owner = &account
	}

	if err := m.server.RemoveDomain(ctx, owner, params.Domain); err != nil {
		return nil, rpc.Errorf(rpc.CodeActionFailed, "mail for %s could not be removed: %v", params.Domain, err)
	}

	return struct{}{}, nil
}

// removeMailbox implements mail.mailbox_remove: the mailbox's mail goes. An
// account that is gone took its mail with it.
func (m mailActions) removeMailbox(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[mailboxParams](raw)
	if err != nil {
		return nil, err
	}

	account, found, err := m.accounts.findAccount(ctx, params.Username)
	if err != nil || !found {
		return struct{}{}, err
	}

	if err := m.server.RemoveMailbox(ctx, account, params.Domain, params.LocalPart); err != nil {
		return nil, rpc.Errorf(rpc.CodeActionFailed, "the mail of %s@%s could not be removed: %v", params.LocalPart, params.Domain, err)
	}

	return struct{}{}, nil
}
