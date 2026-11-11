package actions

import (
	"context"
	"encoding/json"

	"rayxo/agent/internal/cron"
	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/validate"
)

// cronParams are the params of cron.apply: every cron job of the account,
// and the address their output is mailed to ("" or missing mails nothing).
type cronParams struct {
	Username string     `json:"username"`
	Jobs     []cron.Job `json:"jobs"`
	MailTo   string     `json:"mail_to"`
}

func (p cronParams) account() string { return p.Username }

func (p cronParams) validate() error {
	return firstError(validate.Username(p.Username), cron.Validate(p.Jobs), cron.ValidateMailTo(p.MailTo))
}

// cronActions implements the cron.* methods.
type cronActions struct {
	accounts accountActions
}

// cronMethods returns the cron.* methods by name. They lock the account
// like the account.* methods.
func cronMethods(system accountSystem) map[string]rpc.Method {
	actions := cronActions{accounts: accountActions{system: system}}

	return map[string]rpc.Method{
		"cron.apply": {Handler: actions.apply, LockKey: webLockKey[cronParams]},
	}
}

// apply implements cron.apply: the account's crontab becomes exactly the
// given jobs, run by the cron daemon as the account's user, which mails
// their output to mail_to; without jobs it is removed. Calling it again changes nothing. A suspended account keeps
// its crontab: cron checks the account through PAM, and the expired account
// of a suspension does not pass.
func (c cronActions) apply(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[cronParams](raw)
	if err != nil {
		return nil, err
	}

	user, err := c.accounts.requireAccount(ctx, params.Username)
	if err != nil {
		return nil, err
	}

	var crontab []byte
	if len(params.Jobs) > 0 {
		crontab = cron.Render(params.Jobs, params.MailTo)
	}

	if err := c.accounts.system.SetCrontab(ctx, user.Name, crontab); err != nil {
		return nil, systemError(err)
	}

	return struct{}{}, nil
}
