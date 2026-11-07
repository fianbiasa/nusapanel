package actions

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"strings"

	"nusapanel/agent/internal/rpc"
	"nusapanel/agent/internal/sysuser"
	"nusapanel/agent/internal/validate"
)

// How hosting accounts look on the server (docs/04-agent-dan-server.md 4.4).
// Every account the panel creates carries accountMarker as its GECOS
// comment; the account actions never touch a user without it.
const (
	accountMarker = "nusapanel"
	minAccountUID = 1000
	homeRoot      = "/home"
	homeMode      = fs.FileMode(0o710)
	noLoginShell  = "/usr/sbin/nologin"

	// accessGroup owns every home, so the web server (a member) can reach
	// the domains inside it but cannot list the home.
	accessGroup   = "nusa-access"
	webServerUser = "www-data"
)

// homeDirs are the directories inside every home, owned by the account.
var homeDirs = []sysuser.Dir{
	{Name: "domains", Mode: 0o711},
	{Name: "tmp", Mode: 0o700},
	{Name: "backups", Mode: 0o700},
	{Name: ".trash", Mode: 0o700},
}

// accountSystem is what the account actions need from the operating system.
// sysuser.System implements it; tests use a fake.
type accountSystem interface {
	// LookupUser returns an error wrapping sysuser.ErrNotFound when the
	// user does not exist.
	LookupUser(ctx context.Context, name string) (sysuser.User, error)
	EnsureGroupMember(ctx context.Context, group, member string) (gid int, err error)
	// AddUser returns an error wrapping sysuser.ErrNameTaken when the user
	// or group name is in use.
	AddUser(ctx context.Context, user sysuser.NewUser) error
	SetPasswordLock(ctx context.Context, name string, locked bool) error
	SetShell(ctx context.Context, name, shell string) error
	KillProcesses(ctx context.Context, uid int) error
	DeleteUser(ctx context.Context, name string) error
	PrepareHome(ctx context.Context, home sysuser.Home) error
}

// accountParams are the params of every account.* method.
type accountParams struct {
	Username string `json:"username"`
}

// accountInfo is the result of account.create.
type accountInfo struct {
	Username string `json:"username"`
	UID      int    `json:"uid"`
	GID      int    `json:"gid"`
	Home     string `json:"home"`
}

// accountActions implements the account.* methods on top of a system.
type accountActions struct {
	system accountSystem
}

// accountMethods returns the account.* methods by name. Calls for the same
// username run one at a time.
func accountMethods(system accountSystem) map[string]rpc.Method {
	accounts := accountActions{system: system}

	return map[string]rpc.Method{
		"account.create":    {Handler: accounts.create, LockKey: accountLockKey},
		"account.suspend":   {Handler: accounts.suspend, LockKey: accountLockKey},
		"account.unsuspend": {Handler: accounts.unsuspend, LockKey: accountLockKey},
		"account.delete":    {Handler: accounts.remove, LockKey: accountLockKey},
	}
}

// accountLockKey locks the account named in params.
func accountLockKey(raw json.RawMessage) (string, error) {
	params, err := decodeAccountParams(raw)
	if err != nil {
		return "", err
	}

	return "account:" + params.Username, nil
}

// decodeAccountParams strictly decodes params and validates the username.
func decodeAccountParams(raw json.RawMessage) (accountParams, error) {
	var params accountParams
	if err := rpc.DecodeParams(raw, &params); err != nil {
		return params, err
	}

	if err := validate.Username(params.Username); err != nil {
		return params, rpc.Errorf(rpc.CodeInvalidParams, "invalid params: %v", err)
	}

	return params, nil
}

// create adds the Linux user for a hosting account, with its own group and
// home layout, and returns its IDs. Calling it again for an existing
// nusapanel account repairs the home and returns the same result, so a
// failed create can simply be retried.
func (a accountActions) create(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeAccountParams(raw)
	if err != nil {
		return nil, err
	}

	user, found, err := a.findAccount(ctx, params.Username)
	if err != nil {
		return nil, err
	}

	accessGID, err := a.system.EnsureGroupMember(ctx, accessGroup, webServerUser)
	if err != nil {
		return nil, systemError(err)
	}

	if !found {
		if user, err = a.addUser(ctx, params.Username); err != nil {
			return nil, err
		}
	}

	err = a.system.PrepareHome(ctx, sysuser.Home{
		Path:      user.Home,
		Mode:      homeMode,
		UID:       user.UID,
		AccessGID: accessGID,
		GID:       user.GID,
		Dirs:      homeDirs,
	})
	if err != nil {
		return nil, systemError(err)
	}

	return accountInfo{Username: user.Name, UID: user.UID, GID: user.GID, Home: user.Home}, nil
}

// addUser creates the Linux user and returns it as the system now reports it.
func (a accountActions) addUser(ctx context.Context, name string) (sysuser.User, error) {
	err := a.system.AddUser(ctx, sysuser.NewUser{
		Name:    name,
		Home:    homeOf(name),
		Shell:   noLoginShell,
		Comment: accountMarker,
	})
	if errors.Is(err, sysuser.ErrNameTaken) {
		return sysuser.User{}, rpc.Errorf(rpc.CodeActionFailed, "username %s is already used by a system account", name)
	}

	if err != nil {
		return sysuser.User{}, systemError(err)
	}

	user, found, err := a.findAccount(ctx, name)
	if err == nil && !found {
		err = fmt.Errorf("user %s is missing right after it was created", name)
	}

	return user, err
}

// suspend locks the account's password and takes away its login shell.
func (a accountActions) suspend(ctx context.Context, raw json.RawMessage) (any, error) {
	user, err := a.existingAccount(ctx, raw)
	if err != nil {
		return nil, err
	}

	if err := a.system.SetPasswordLock(ctx, user.Name, true); err != nil {
		return nil, systemError(err)
	}

	if err := a.system.SetShell(ctx, user.Name, noLoginShell); err != nil {
		return nil, systemError(err)
	}

	return struct{}{}, nil
}

// unsuspend unlocks the account's password.
func (a accountActions) unsuspend(ctx context.Context, raw json.RawMessage) (any, error) {
	user, err := a.existingAccount(ctx, raw)
	if err != nil {
		return nil, err
	}

	if err := a.system.SetPasswordLock(ctx, user.Name, false); err != nil {
		return nil, systemError(err)
	}

	return struct{}{}, nil
}

// remove implements account.delete: it kills the account's processes, then
// removes the user and its home. Deleting an account that no longer exists
// succeeds.
func (a accountActions) remove(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeAccountParams(raw)
	if err != nil {
		return nil, err
	}

	user, found, err := a.findAccount(ctx, params.Username)
	if err != nil {
		return nil, err
	}

	if !found {
		return struct{}{}, nil
	}

	if err := a.system.KillProcesses(ctx, user.UID); err != nil {
		return nil, systemError(err)
	}

	if err := a.system.DeleteUser(ctx, user.Name); err != nil {
		return nil, systemError(err)
	}

	return struct{}{}, nil
}

// existingAccount decodes params and returns the nusapanel account they
// name, failing when it does not exist.
func (a accountActions) existingAccount(ctx context.Context, raw json.RawMessage) (sysuser.User, error) {
	params, err := decodeAccountParams(raw)
	if err != nil {
		return sysuser.User{}, err
	}

	user, found, err := a.findAccount(ctx, params.Username)
	if err == nil && !found {
		err = rpc.Errorf(rpc.CodeActionFailed, "account %s does not exist", params.Username)
	}

	return user, err
}

// findAccount looks up name and makes sure it is an account nusapanel
// manages. found is false when no user has that name.
func (a accountActions) findAccount(ctx context.Context, name string) (user sysuser.User, found bool, err error) {
	user, err = a.system.LookupUser(ctx, name)
	if errors.Is(err, sysuser.ErrNotFound) {
		return sysuser.User{}, false, nil
	}

	if err != nil {
		return sysuser.User{}, false, systemError(err)
	}

	if err := checkManaged(user); err != nil {
		return sysuser.User{}, false, err
	}

	return user, true, nil
}

// checkManaged refuses every user that does not look exactly like an
// account made by account.create: system and service users, people's own
// logins, and accounts whose home was moved.
func checkManaged(user sysuser.User) error {
	marker, _, _ := strings.Cut(user.Comment, ",")
	if marker != accountMarker || user.UID < minAccountUID {
		return rpc.Errorf(rpc.CodeActionFailed, "username %s is already used by a system account", user.Name)
	}

	if want := homeOf(user.Name); user.Home != want {
		return rpc.Errorf(rpc.CodeActionFailed, "account %s has home %s instead of %s", user.Name, user.Home, want)
	}

	return nil
}

// homeOf returns the home directory of the account name.
func homeOf(name string) string {
	return homeRoot + "/" + name
}

// systemError returns err from the operating system as an internal error:
// its details only go to the agent log. On platforms without account
// support, the panel gets a readable message instead.
func systemError(err error) error {
	if errors.Is(err, errors.ErrUnsupported) {
		return rpc.Errorf(rpc.CodeActionFailed, "accounts can only be managed on Linux")
	}

	return err
}
