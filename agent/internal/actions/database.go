package actions

import (
	"context"
	"encoding/json"
	"errors"
	"log/slog"

	"rayxo/agent/internal/database"
	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/validate"
)

// databaseServer is what the db.* actions need. *database.Manager
// implements it.
type databaseServer interface {
	CreateDatabase(ctx context.Context, user, name string) error
	DeleteDatabase(ctx context.Context, user, name string) error
	ApplyUser(ctx context.Context, user, name, hash string, databases []string) error
	DeleteUser(ctx context.Context, user, name string) error
	ApplyOwner(ctx context.Context, user, hash string, databases []string) error
	CloseOwner(ctx context.Context, user string) error
	Report(ctx context.Context) ([]database.Size, error)
}

// databaseParams are the params of db.create_database and
// db.delete_database.
type databaseParams struct {
	Username string `json:"username"`
	Database string `json:"database"`
}

func (p databaseParams) account() string { return p.Username }

func (p databaseParams) validate() error {
	return database.ValidateName(p.Username, p.Database, database.MaxDatabaseName)
}

// applyUserParams are the params of db.apply_user: the whole state of one
// database user. PasswordHash is a mysql_native_password hash, so the
// password itself never reaches the agent.
type applyUserParams struct {
	Username     string   `json:"username"`
	User         string   `json:"user"`
	PasswordHash string   `json:"password_hash"`
	Databases    []string `json:"databases"`
}

func (p applyUserParams) account() string { return p.Username }

func (p applyUserParams) validate() error {
	errs := []error{
		database.ValidateName(p.Username, p.User, database.MaxUserName),
		database.ValidatePasswordHash(p.PasswordHash),
	}

	for _, name := range p.Databases {
		errs = append(errs, database.ValidateName(p.Username, name, database.MaxDatabaseName))
	}

	return firstError(errs...)
}

// databaseUserParams are the params of db.delete_user.
type databaseUserParams struct {
	Username string `json:"username"`
	User     string `json:"user"`
}

func (p databaseUserParams) account() string { return p.Username }

func (p databaseUserParams) validate() error {
	return database.ValidateName(p.Username, p.User, database.MaxUserName)
}

// openSSOParams are the params of db.open_sso: the password hash the
// account's owner user gets for one phpMyAdmin session, and the databases
// it may use.
type openSSOParams struct {
	Username     string   `json:"username"`
	PasswordHash string   `json:"password_hash"`
	Databases    []string `json:"databases"`
}

func (p openSSOParams) account() string { return p.Username }

func (p openSSOParams) validate() error {
	errs := []error{validate.Username(p.Username), database.ValidatePasswordHash(p.PasswordHash)}

	for _, name := range p.Databases {
		errs = append(errs, database.ValidateName(p.Username, name, database.MaxDatabaseName))
	}

	return firstError(errs...)
}

// closeSSOParams are the params of db.close_sso.
type closeSSOParams struct {
	Username string `json:"username"`
}

func (p closeSSOParams) account() string { return p.Username }

func (p closeSSOParams) validate() error { return validate.Username(p.Username) }

// databaseReport is the result of db.report.
type databaseReport struct {
	Databases []database.Size `json:"databases"`
}

// databaseActions implements the db.* methods.
type databaseActions struct {
	accounts accountActions
	server   databaseServer
}

// databaseMethods returns the db.* methods by name. Those that change an
// account lock it, like the account.* methods.
func databaseMethods(system accountSystem, server databaseServer) map[string]rpc.Method {
	actions := databaseActions{accounts: accountActions{system: system}, server: server}

	return map[string]rpc.Method{
		"db.create_database": {Handler: actions.createDatabase, LockKey: webLockKey[databaseParams]},
		"db.delete_database": {Handler: actions.deleteDatabase, LockKey: webLockKey[databaseParams]},
		"db.apply_user":      {Handler: actions.applyUser, LockKey: webLockKey[applyUserParams]},
		"db.delete_user":     {Handler: actions.deleteUser, LockKey: webLockKey[databaseUserParams]},
		"db.open_sso":        {Handler: actions.openSSO, LockKey: webLockKey[openSSOParams]},
		"db.close_sso":       {Handler: actions.closeSSO, LockKey: webLockKey[closeSSOParams]},
		"db.report":          {Handler: actions.report},
	}
}

// createDatabase implements db.create_database: it creates the database
// (utf8mb4) unless it exists.
func (d databaseActions) createDatabase(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[databaseParams](raw)
	if err != nil {
		return nil, err
	}

	if _, err := d.accounts.requireAccount(ctx, params.Username); err != nil {
		return nil, err
	}

	if err := d.server.CreateDatabase(ctx, params.Username, params.Database); err != nil {
		return nil, databaseError(err, "create database "+params.Database)
	}

	return struct{}{}, nil
}

// deleteDatabase implements db.delete_database: it drops the database and
// everyone's access to it. A database that does not exist succeeds, also
// for an account that no longer exists.
func (d databaseActions) deleteDatabase(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[databaseParams](raw)
	if err != nil {
		return nil, err
	}

	if err := d.server.DeleteDatabase(ctx, params.Username, params.Database); err != nil {
		return nil, databaseError(err, "delete database "+params.Database)
	}

	return struct{}{}, nil
}

// applyUser implements db.apply_user: it creates the user or updates it to
// the given password and access to exactly the given databases. Calling it
// again changes nothing.
func (d databaseActions) applyUser(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[applyUserParams](raw)
	if err != nil {
		return nil, err
	}

	if _, err := d.accounts.requireAccount(ctx, params.Username); err != nil {
		return nil, err
	}

	if err := d.server.ApplyUser(ctx, params.Username, params.User, params.PasswordHash, params.Databases); err != nil {
		return nil, databaseError(err, "set up database user "+params.User)
	}

	return struct{}{}, nil
}

// deleteUser implements db.delete_user. A user that does not exist
// succeeds, also for an account that no longer exists.
func (d databaseActions) deleteUser(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[databaseUserParams](raw)
	if err != nil {
		return nil, err
	}

	if err := d.server.DeleteUser(ctx, params.Username, params.User); err != nil {
		return nil, databaseError(err, "delete database user "+params.User)
	}

	return struct{}{}, nil
}

// openSSO implements db.open_sso: the account's owner user (named like the
// account) gets the given password and access to exactly the given
// databases, so phpMyAdmin can sign in as it.
func (d databaseActions) openSSO(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[openSSOParams](raw)
	if err != nil {
		return nil, err
	}

	if _, err := d.accounts.requireAccount(ctx, params.Username); err != nil {
		return nil, err
	}

	if err := d.server.ApplyOwner(ctx, params.Username, params.PasswordHash, params.Databases); err != nil {
		return nil, databaseError(err, "prepare phpMyAdmin for "+params.Username)
	}

	return struct{}{}, nil
}

// closeSSO implements db.close_sso: the account's owner user gets a
// password nobody knows, which ends its phpMyAdmin session. It succeeds
// when the owner user or the account no longer exists.
func (d databaseActions) closeSSO(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[closeSSOParams](raw)
	if err != nil {
		return nil, err
	}

	if err := d.server.CloseOwner(ctx, params.Username); err != nil {
		return nil, databaseError(err, "end the phpMyAdmin session of "+params.Username)
	}

	return struct{}{}, nil
}

// report implements db.report: the size of every account database. It
// only reads, so it locks nothing.
func (d databaseActions) report(ctx context.Context, raw json.RawMessage) (any, error) {
	if err := rpc.DecodeParams(raw, &struct{}{}); err != nil {
		return nil, err
	}

	sizes, err := d.server.Report(ctx)
	if err != nil {
		return nil, databaseError(err, "measure the databases")
	}

	return databaseReport{Databases: sizes}, nil
}

// databaseError turns a MariaDB failure into a message panel users can
// read; MariaDB's own message stays in the agent log.
func databaseError(err error, what string) error {
	if errors.Is(err, database.ErrServer) {
		slog.Error("MariaDB refused a command", "task", what, "error", err)

		return rpc.Errorf(rpc.CodeActionFailed, "MariaDB could not %s; the agent log has the details", what)
	}

	return err
}
