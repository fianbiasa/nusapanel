package mail

import (
	"errors"
	"fmt"
	"os"
	"slices"
	"strconv"
	"strings"

	"rayxo/agent/internal/validate"
)

// MaxSendLimit is the largest daily send limit, in recipients.
const MaxSendLimit = 10_000_000

// maxSendLimitAccounts bounds the accounts of one send limit map.
const maxSendLimitAccounts = 100_000

// SendLimits are the daily send limits of the hosting accounts, which
// Rspamd's ratelimit module applies (setup.sh's ratelimit.lua reads the map
// ApplySendLimits writes). Limits count recipients per 24 hours; 0 is no
// limit.
type SendLimits struct {
	// AccountLimit is the limit of each hosting account.
	AccountLimit int `json:"account_limit"`
	// ServerLimit is the limit of all hosting accounts together.
	ServerLimit int `json:"server_limit"`
	// Accounts are the hosting accounts and their domains, so Rspamd knows
	// whose mail it sees.
	Accounts []SendLimitAccount `json:"accounts"`
	// Mailboxes are the mailboxes with their own limit, for the mail they
	// send signed in.
	Mailboxes []SendLimitMailbox `json:"mailboxes"`
}

// SendLimitMailbox is a mailbox with its own daily send limit.
type SendLimitMailbox struct {
	Address string `json:"address"`
	Limit   int    `json:"limit"`
}

// SendLimitAccount is a hosting account and the domains its mail may come
// from.
type SendLimitAccount struct {
	Username string   `json:"username"`
	Domains  []string `json:"domains"`
}

// Validate returns an error unless every name and number is sound and no
// domain belongs to two accounts.
func (s SendLimits) Validate() error {
	for name, limit := range map[string]int{"account_limit": s.AccountLimit, "server_limit": s.ServerLimit} {
		if limit < 0 || limit > MaxSendLimit {
			return fmt.Errorf("%s must be 0 (no limit) to %d", name, MaxSendLimit)
		}
	}

	if len(s.Accounts) > maxSendLimitAccounts {
		return fmt.Errorf("at most %d accounts", maxSendLimitAccounts)
	}

	usernames := map[string]bool{}
	domains := map[string]bool{}

	for _, account := range s.Accounts {
		if err := validate.Username(account.Username); err != nil {
			return err
		}

		if usernames[account.Username] {
			return fmt.Errorf("account %q is named twice", account.Username)
		}

		usernames[account.Username] = true

		for _, domain := range account.Domains {
			if err := validate.Domain(domain); err != nil {
				return err
			}

			if domains[domain] {
				return fmt.Errorf("domain %q is named twice", domain)
			}

			domains[domain] = true
		}
	}

	if len(s.Mailboxes) > maxSendLimitAccounts {
		return fmt.Errorf("at most %d mailboxes", maxSendLimitAccounts)
	}

	addresses := map[string]bool{}

	for _, mailbox := range s.Mailboxes {
		localPart, domain, ok := strings.Cut(mailbox.Address, "@")
		if !ok {
			return fmt.Errorf("mailbox %q is not an address", mailbox.Address)
		}

		if err := firstError(ValidateLocalPart(localPart), validate.Domain(domain)); err != nil {
			return err
		}

		if mailbox.Limit < 1 || mailbox.Limit > MaxSendLimit {
			return fmt.Errorf("the limit of %s must be 1 to %d", mailbox.Address, MaxSendLimit)
		}

		if addresses[mailbox.Address] {
			return fmt.Errorf("mailbox %q is named twice", mailbox.Address)
		}

		addresses[mailbox.Address] = true
	}

	return nil
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

// ApplySendLimits writes the send limit map Rspamd reads (it notices the new
// file by itself). Every account's mail is known by the addresses Postfix
// lets it send from: an address at one of its domains, or its login name at
// the server's own name (/etc/mailname) for its local sendmail.
func (m *Manager) ApplySendLimits(limits SendLimits) error {
	if err := limits.Validate(); err != nil {
		return err
	}

	content, err := os.ReadFile(m.cfg.MailnamePath)
	if err != nil {
		return fmt.Errorf("the server's mail name: %w", err)
	}

	mailname := strings.TrimSpace(strings.SplitN(string(content), "\n", 2)[0])
	if err := validate.Domain(mailname); err != nil {
		return fmt.Errorf("the server's mail name (%s): %w", m.cfg.MailnamePath, err)
	}

	var lines []string

	if limits.AccountLimit > 0 {
		lines = append(lines, "account "+strconv.Itoa(limits.AccountLimit))
	}

	if limits.ServerLimit > 0 {
		lines = append(lines, "server "+strconv.Itoa(limits.ServerLimit))
	}

	for _, account := range limits.Accounts {
		lines = append(lines, "sender:"+account.Username+"@"+mailname+" "+account.Username)

		for _, domain := range account.Domains {
			lines = append(lines, "sender:@"+domain+" "+account.Username)
		}
	}

	for _, mailbox := range limits.Mailboxes {
		lines = append(lines, "mailbox:"+mailbox.Address+" "+strconv.Itoa(mailbox.Limit))
	}

	slices.Sort(lines)

	text := "# Written by rayxod: the daily send limits (recipients per 24 hours) and whose mail is whose.\n"
	if len(lines) > 0 {
		text += strings.Join(lines, "\n") + "\n"
	}

	current, err := os.ReadFile(m.cfg.SendLimitMap)
	if err == nil && string(current) == text {
		return nil
	}

	if err != nil && !errors.Is(err, os.ErrNotExist) {
		return err
	}

	gid, err := m.cfg.LookupGroup(m.cfg.KeyGroup)
	if err != nil {
		return fmt.Errorf("group %s: %w", m.cfg.KeyGroup, err)
	}

	// Only Rspamd reads which domains belong to which account.
	return m.writeFile(m.cfg.SendLimitMap, []byte(text), 0o640, gid)
}
