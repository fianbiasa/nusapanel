package actions

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"slices"
	"strings"

	"rayxo/agent/internal/files"
	"rayxo/agent/internal/jail"
	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/sshkey"
	"rayxo/agent/internal/sysuser"
	"rayxo/agent/internal/validate"
)

// SSH access of hosting accounts. Every account has SFTP in its own home
// (sshd's block for rayxo-sftp). An account whose package allows SSH also
// joins rayxo-ssh, whose sshd block comes first: it gets a shell in a jail
// of its own (internal/jail), where it sees the server's programs and its
// own home, but not other accounts.
const (
	sshGroup   = "rayxo-ssh"
	loginShell = "/bin/bash"

	authorizedKeysPath = ".ssh/authorized_keys"
	// maxSSHKeys is how many keys an account may add in the panel.
	maxSSHKeys = 20
	keysBegin  = "# BEGIN RAYXO: keys added in the panel. The lines up to END RAYXO are replaced from the panel."
	keysEnd    = "# END RAYXO"
)

// accountJails builds and removes the SSH jails. *jail.Manager implements
// it.
type accountJails interface {
	Enable(account jail.Account) error
	Disable(name string) error
	Exists(name string) bool
}

type setSSHParams struct {
	Username string `json:"username"`
	Enabled  bool   `json:"enabled"`
}

func (p setSSHParams) account() string { return p.Username }
func (p setSSHParams) validate() error { return validate.Username(p.Username) }

// sshKeysParams are every key the account added in the panel, which
// replace the ones written before.
type sshKeysParams struct {
	Username string   `json:"username"`
	Keys     []string `json:"keys"`
}

func (p sshKeysParams) account() string { return p.Username }

func (p sshKeysParams) validate() error {
	if err := validate.Username(p.Username); err != nil {
		return err
	}

	if len(p.Keys) > maxSSHKeys {
		return fmt.Errorf("an account may have at most %d keys", maxSSHKeys)
	}

	for i, key := range p.Keys {
		if _, err := sshkey.Parse(key); err != nil {
			return fmt.Errorf("key %d: %w", i+1, err)
		}
	}

	return nil
}

// sshActions implements account.set_ssh and account.ssh_keys.
type sshActions struct {
	accounts accountActions
	helper   fileHelper
}

// sshMethods returns the SSH methods by name. They lock the account, like
// suspending and deleting it.
func sshMethods(system accountSystem, jails accountJails, helper fileHelper) map[string]rpc.Method {
	s := sshActions{accounts: accountActions{system: system, jails: jails}, helper: helper}

	return map[string]rpc.Method{
		"account.set_ssh":  {Handler: s.setSSH, LockKey: webLockKey[setSSHParams]},
		"account.ssh_keys": {Handler: s.setKeys, LockKey: webLockKey[sshKeysParams]},
	}
}

// setSSH implements account.set_ssh. Enabling builds the jail before the
// account joins rayxo-ssh, so a login never finds it missing; disabling
// takes the account out first, then ends its sessions and removes the jail.
// The account keeps SFTP either way.
func (s sshActions) setSSH(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[setSSHParams](raw)
	if err != nil {
		return nil, err
	}

	system := s.accounts.system

	if !params.Enabled {
		user, found, err := s.accounts.findAccount(ctx, params.Username)
		if err != nil {
			return nil, err
		}

		if found {
			if err := system.SetShell(ctx, user.Name, noLoginShell); err != nil {
				return nil, systemError(err)
			}

			if err := system.RemoveGroupMember(ctx, sshGroup, user.Name); err != nil {
				return nil, systemError(err)
			}
		}

		if err := s.accounts.disableJail(params.Username); err != nil {
			return nil, err
		}

		return struct{}{}, nil
	}

	user, err := s.accounts.requireAccount(ctx, params.Username)
	if err != nil {
		return nil, err
	}

	if err := s.accounts.jails.Enable(jailAccount(user)); err != nil {
		return nil, jailError(err)
	}

	if _, err := system.EnsureGroupMember(ctx, sshGroup, user.Name); err != nil {
		return nil, systemError(err)
	}

	if err := system.SetShell(ctx, user.Name, loginShell); err != nil {
		return nil, systemError(err)
	}

	return struct{}{}, nil
}

// setKeys implements account.ssh_keys: the keys go between the RAYXO lines
// of ~/.ssh/authorized_keys, written as the account; keys the account put
// in the file itself stay. They work for SFTP and, with SSH access, for
// the shell.
func (s sshActions) setKeys(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[sshKeysParams](raw)
	if err != nil {
		return nil, err
	}

	user, err := s.accounts.requireAccount(ctx, params.Username)
	if err != nil {
		return nil, err
	}

	var keys []string

	for _, line := range params.Keys {
		key, _ := sshkey.Parse(line)
		if !slices.Contains(keys, key.String()) {
			keys = append(keys, key.String())
		}
	}

	account := files.Account{UID: user.UID, GID: user.GID, Home: user.Home}

	var current files.Content

	err = s.helper.Run(ctx, account, files.OpRead, files.PathParams{Path: authorizedKeysPath}, nil, &current)
	if err != nil && !hasFileReason(err, files.ReasonNotFound) {
		return nil, fileError(err)
	}

	var ignored json.RawMessage

	err = s.helper.Run(ctx, account, files.OpMkdir, files.PathParams{Path: ".ssh"}, nil, &ignored)
	if err != nil && !hasFileReason(err, files.ReasonExists) {
		return nil, fileError(err)
	}

	// sshd ignores keys that others may change.
	if err := s.chmod(ctx, account, ".ssh", 0o700); err != nil {
		return nil, err
	}

	var written files.Content

	write := files.WriteParams{Path: authorizedKeysPath, Content: withKeys(current.Content, keys), Version: current.Version}
	if err := s.helper.Run(ctx, account, files.OpWrite, write, nil, &written); err != nil {
		return nil, fileError(err)
	}

	if err := s.chmod(ctx, account, authorizedKeysPath, 0o600); err != nil {
		return nil, err
	}

	return map[string]int{"keys": len(keys)}, nil
}

func (s sshActions) chmod(ctx context.Context, account files.Account, path string, mode uint32) error {
	var outcome files.Outcome

	if err := s.helper.Run(ctx, account, files.OpChmod, files.ChmodParams{Paths: []string{path}, Mode: mode}, nil, &outcome); err != nil {
		return fileError(err)
	}

	if len(outcome.Failed) > 0 {
		return &rpc.Error{
			Code:    rpc.CodeActionFailed,
			Message: outcome.Failed[0].Message,
			Data:    map[string]string{"reason": outcome.Failed[0].Reason},
		}
	}

	return nil
}

// withKeys is authorized_keys with keys between the RAYXO lines, after
// the account's own lines. No keys leaves no RAYXO lines.
func withKeys(existing string, keys []string) string {
	var own []string

	managed := false

	for line := range strings.Lines(existing) {
		line = strings.TrimRight(line, "\n")
		trimmed := strings.TrimSpace(line)

		switch {
		case strings.HasPrefix(trimmed, "# BEGIN RAYXO"):
			managed = true
		case managed && trimmed == keysEnd:
			managed = false
		case !managed:
			own = append(own, line)
		}
	}

	for len(own) > 0 && strings.TrimSpace(own[len(own)-1]) == "" {
		own = own[:len(own)-1]
	}

	var out strings.Builder

	for _, line := range own {
		out.WriteString(line + "\n")
	}

	if len(keys) == 0 {
		return out.String()
	}

	if len(own) > 0 {
		out.WriteString("\n")
	}

	out.WriteString(keysBegin + "\n")

	for _, key := range keys {
		out.WriteString(key + "\n")
	}

	out.WriteString(keysEnd + "\n")

	return out.String()
}

// disableJail ends the SSH sessions of name and removes its jail, if it
// has one.
func (a accountActions) disableJail(name string) error {
	if a.jails == nil || !a.jails.Exists(name) {
		return nil
	}

	return jailError(a.jails.Disable(name))
}

// RestoreJails mounts the SSH jails again when the agent starts, as mounts
// do not survive a reboot, and removes the jails of deleted accounts.
func RestoreJails(ctx context.Context) error {
	accounts := accountActions{system: sysuser.System{}}

	return jail.New(jail.DefaultConfig()).Restore(func(name string) (jail.Account, bool, error) {
		user, found, err := accounts.findAccount(ctx, name)
		if err != nil || !found {
			return jail.Account{}, false, err
		}

		return jailAccount(user), true, nil
	})
}

func jailAccount(user sysuser.User) jail.Account {
	return jail.Account{Name: user.Name, UID: user.UID, GID: user.GID, Home: user.Home}
}

// jailError keeps the details of a jail that could not be built or removed
// in the agent log.
func jailError(err error) error {
	if errors.Is(err, errors.ErrUnsupported) {
		return rpc.Errorf(rpc.CodeActionFailed, "SSH access can only be managed on Linux")
	}

	return err
}

// hasFileReason reports whether err is a files helper failure for reason.
func hasFileReason(err error, reason string) bool {
	var failure *files.Error

	return errors.As(err, &failure) && failure.Reason == reason
}
