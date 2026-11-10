package actions

import (
	"cmp"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"regexp"
	"strings"

	"rayxo/agent/internal/quota"
	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/sysuser"
	"rayxo/agent/internal/validate"
)

// How hosting accounts look on the server (docs/04-agent-dan-server.md 4.4).
// Every account the panel creates carries accountMarker as its GECOS
// comment; the account actions never touch a user without it.
const (
	accountMarker = "rayxo"
	minAccountUID = 1000
	homeRoot      = "/home"
	homeMode      = fs.FileMode(0o710)
	noLoginShell  = "/usr/sbin/nologin"

	// accessGroup owns every home, so the web server (a member) can reach
	// the domains inside it but cannot list the home.
	accessGroup   = "rayxo-access"
	webServerUser = "www-data"
	// sftpGroup holds every hosting account. sshd gives its members SFTP
	// only, in their home: no shell, no forwarding (setup.sh configures it).
	sftpGroup = "rayxo-sftp"
)

// passwordHashPattern is a SHA512-CRYPT hash as chpasswd -e takes it.
var passwordHashPattern = regexp.MustCompile(`^\$6\$(rounds=[0-9]{4,9}\$)?[./0-9A-Za-z]{1,16}\$[./0-9A-Za-z]{86}$`)

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
	// ListUsers returns every user, for quota.report.
	ListUsers(ctx context.Context) ([]sysuser.User, error)
	KillProcesses(ctx context.Context, uid int) error
	DeleteUser(ctx context.Context, name string) error
	PrepareHome(ctx context.Context, home sysuser.Home) error
	// SetCrontab installs the user's crontab, or removes it when empty.
	SetCrontab(ctx context.Context, name string, crontab []byte) error
	// SetPassword sets the user's password hash as it is, such as "!" for
	// no password.
	SetPassword(ctx context.Context, name, hash string) error
}

// accountWeb is what the account actions need from the web server side:
// suspending, unsuspending and removing everything web related of an
// account. *webserver.Manager implements it; tests use a fake.
type accountWeb interface {
	SuspendAccount(ctx context.Context, user string) error
	UnsuspendAccount(ctx context.Context, user string) error
	RemoveAccount(ctx context.Context, user string) error
}

// accountDatabases is what the account actions need from MariaDB.
// *database.Manager implements it.
type accountDatabases interface {
	// RemoveAccount drops every database and user of the account.
	RemoveAccount(ctx context.Context, user string) error
	// LockAccount locks or unlocks every database user of the account.
	LockAccount(ctx context.Context, user string, lock bool) error
}

// accountQuota is what the account actions need from disk quotas.
// *quota.Manager implements it.
type accountQuota interface {
	// SetUserLimits returns false, changing nothing, when user quotas are
	// off on the filesystem of the homes.
	SetUserLimits(ctx context.Context, user string, limits quota.Limits) (applied bool, err error)
	// ClearUserLimits removes the limits of a user about to be deleted. It
	// never fails; problems are logged.
	ClearUserLimits(ctx context.Context, user string)
	Report(ctx context.Context) (quota.Report, error)
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

// accountActions implements the account.* and quota.* methods on top of a
// system.
type accountActions struct {
	system    accountSystem
	web       accountWeb
	quotas    accountQuota
	databases accountDatabases
}

// accountMethods returns the account.* and quota.* methods by name. Calls
// for the same username run one at a time; quota.report only reads and
// locks nothing.
func accountMethods(system accountSystem, web accountWeb, quotas accountQuota, databases accountDatabases) map[string]rpc.Method {
	accounts := accountActions{system: system, web: web, quotas: quotas, databases: databases}

	return map[string]rpc.Method{
		"account.create":       {Handler: accounts.create, LockKey: accountLockKey},
		"account.suspend":      {Handler: accounts.suspend, LockKey: accountLockKey},
		"account.unsuspend":    {Handler: accounts.unsuspend, LockKey: accountLockKey},
		"account.delete":       {Handler: accounts.remove, LockKey: accountLockKey},
		"account.set_quota":    {Handler: accounts.setQuota, LockKey: setQuotaLockKey},
		"account.set_password": {Handler: accounts.setPassword, LockKey: webLockKey[setPasswordParams]},
		"quota.report":         {Handler: accounts.quotaReport},
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
// rayxo account repairs the home and returns the same result, so a
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

	if _, err := a.system.EnsureGroupMember(ctx, sftpGroup, user.Name); err != nil {
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

// suspend locks the account's password, takes away its login shell, shows
// a "temporarily unavailable" page on all its websites and stops its PHP.
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

	if err := a.web.SuspendAccount(ctx, user.Name); err != nil {
		return nil, webError(err, "account "+user.Name)
	}

	if err := a.databases.LockAccount(ctx, user.Name, true); err != nil {
		return nil, databaseError(err, "lock the database users of "+user.Name)
	}

	return struct{}{}, nil
}

// unsuspend unlocks the account's password, serves its websites again and
// starts its PHP.
func (a accountActions) unsuspend(ctx context.Context, raw json.RawMessage) (any, error) {
	user, err := a.existingAccount(ctx, raw)
	if err != nil {
		return nil, err
	}

	if err := a.system.SetPasswordLock(ctx, user.Name, false); err != nil {
		return nil, systemError(err)
	}

	if err := a.web.UnsuspendAccount(ctx, user.Name); err != nil {
		return nil, webError(err, "account "+user.Name)
	}

	if err := a.databases.LockAccount(ctx, user.Name, false); err != nil {
		return nil, databaseError(err, "unlock the database users of "+user.Name)
	}

	return struct{}{}, nil
}

// remove implements account.delete: it removes the account's websites and
// PHP pools, kills its processes, clears its disk quota (when quotas are
// on), then removes the user and its home. Deleting an account that no
// longer exists succeeds, after removing any web configuration it left
// behind.
func (a accountActions) remove(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeAccountParams(raw)
	if err != nil {
		return nil, err
	}

	user, found, err := a.findAccount(ctx, params.Username)
	if err != nil {
		return nil, err
	}

	// PHP-FPM pools go first: their master runs as root and would start new
	// processes for the account right after they are killed.
	if err := a.web.RemoveAccount(ctx, params.Username); err != nil {
		return nil, webError(err, "account "+params.Username)
	}

	// Databases go before the user, so the account is deleted only once
	// nothing of it is left that a new account with the same name would
	// inherit.
	if err := a.databases.RemoveAccount(ctx, params.Username); err != nil {
		return nil, databaseError(err, "delete the databases of "+params.Username)
	}

	if !found {
		return struct{}{}, nil
	}

	if err := a.system.KillProcesses(ctx, user.UID); err != nil {
		return nil, systemError(err)
	}

	// Quota limits belong to the UID, not the user: without this, the next
	// account that gets the UID would inherit them.
	a.quotas.ClearUserLimits(ctx, user.Name)

	// userdel leaves the crontab behind, and a new account with the same
	// name would run its jobs.
	if err := a.system.SetCrontab(ctx, user.Name, nil); err != nil {
		return nil, systemError(err)
	}

	if err := a.system.DeleteUser(ctx, user.Name); err != nil {
		return nil, systemError(err)
	}

	return struct{}{}, nil
}

// existingAccount decodes params and returns the rayxo account they
// name, failing when it does not exist.
func (a accountActions) existingAccount(ctx context.Context, raw json.RawMessage) (sysuser.User, error) {
	params, err := decodeAccountParams(raw)
	if err != nil {
		return sysuser.User{}, err
	}

	return a.requireAccount(ctx, params.Username)
}

// requireAccount returns the rayxo account called name, failing when it
// does not exist.
func (a accountActions) requireAccount(ctx context.Context, name string) (sysuser.User, error) {
	user, found, err := a.findAccount(ctx, name)
	if err == nil && !found {
		err = rpc.Errorf(rpc.CodeActionFailed, "account %s does not exist", name)
	}

	return user, err
}

// findAccount looks up name and makes sure it is an account rayxo
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

// setPasswordParams are the params of account.set_password. PasswordHash is
// a SHA512-CRYPT hash, so the password itself never reaches the agent, or ""
// for no password.
type setPasswordParams struct {
	Username     string `json:"username"`
	PasswordHash string `json:"password_hash"`
}

func (p setPasswordParams) account() string { return p.Username }

func (p setPasswordParams) validate() error {
	if err := validate.Username(p.Username); err != nil {
		return err
	}

	if p.PasswordHash != "" && !passwordHashPattern.MatchString(p.PasswordHash) {
		return errors.New("password_hash must be a SHA512-CRYPT hash ($6$salt$hash) or empty")
	}

	return nil
}

// setPassword implements account.set_password: the account's Linux password
// for SFTP becomes the given hash, or none (locked) for an empty one. The
// account joins the SFTP group, which accounts created before SFTP were not
// in. A suspended account stays expired, so it still cannot log in.
func (a accountActions) setPassword(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[setPasswordParams](raw)
	if err != nil {
		return nil, err
	}

	user, err := a.requireAccount(ctx, params.Username)
	if err != nil {
		return nil, err
	}

	if _, err := a.system.EnsureGroupMember(ctx, sftpGroup, user.Name); err != nil {
		return nil, systemError(err)
	}

	hash := cmp.Or(params.PasswordHash, "!")

	if err := a.system.SetPassword(ctx, user.Name, hash); err != nil {
		return nil, systemError(err)
	}

	return struct{}{}, nil
}
