package actions

import (
	"compress/gzip"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"os"
	"slices"

	"rayxo/agent/internal/database"
	"rayxo/agent/internal/files"
	"rayxo/agent/internal/rpc"
)

// exportParams are the params of db.export: the database, and the download
// transfer the gzipped dump goes to.
type exportParams struct {
	Username string `json:"username"`
	Database string `json:"database"`
	Transfer string `json:"transfer"`
}

func (p exportParams) account() string { return p.Username }

func (p exportParams) validate() error {
	return errors.Join(
		database.ValidateName(p.Username, p.Database, database.MaxDatabaseName),
		files.CheckTransferID(p.Transfer),
	)
}

// importParams are the params of db.import: the database, and the file in
// the account's home (relative to it, plain SQL or gzip) to run in it.
// Remove deletes the file afterwards for good, as the account, also when
// the import failed.
type importParams struct {
	Username string `json:"username"`
	Database string `json:"database"`
	Path     string `json:"path"`
	Remove   bool   `json:"remove"`
}

func (p importParams) account() string { return p.Username }

func (p importParams) validate() error {
	if p.Path == "" {
		return errors.New("path is required")
	}

	return errors.Join(
		database.ValidateName(p.Username, p.Database, database.MaxDatabaseName),
		files.CheckPath(p.Path),
	)
}

// maintainParams are the params of db.maintain.
type maintainParams struct {
	Username  string `json:"username"`
	Database  string `json:"database"`
	Operation string `json:"operation"`
}

func (p maintainParams) account() string { return p.Username }

func (p maintainParams) validate() error {
	if !slices.Contains(database.Operations, p.Operation) {
		return fmt.Errorf("operation %q must be one of %v", p.Operation, database.Operations)
	}

	return database.ValidateName(p.Username, p.Database, database.MaxDatabaseName)
}

// remoteAccessParams are the params of db.set_remote_access.
type remoteAccessParams struct {
	Enabled bool `json:"enabled"`
}

// export implements db.export: a gzipped dump of the database, written to
// the download transfer for the panel to send. It only reads, so it locks
// nothing.
func (d databaseActions) export(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[exportParams](raw)
	if err != nil {
		return nil, err
	}

	if _, err := d.accounts.requireAccount(ctx, params.Username); err != nil {
		return nil, err
	}

	target, err := d.transfers.CreateDownload(params.Transfer)
	if err != nil {
		slog.Warn("database export refused", "transfer", params.Transfer, "error", err)

		return nil, rpc.Errorf(rpc.CodeActionFailed, "the download could not be prepared")
	}

	compressed := gzip.NewWriter(target)
	err = d.server.Export(ctx, params.Username, params.Database, compressed)

	err = errors.Join(err, compressed.Close(), target.Close())
	if err != nil {
		_ = os.Remove(d.transfers.DownloadPath(params.Transfer))

		return nil, databaseError(err, "export database "+params.Database)
	}

	return struct{}{}, nil
}

// importFile implements db.import: it runs the SQL of a file in the
// account's home in the database, as the account (see database.Import).
func (d databaseActions) importFile(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[importParams](raw)
	if err != nil {
		return nil, err
	}

	user, err := d.accounts.requireAccount(ctx, params.Username)
	if err != nil {
		return nil, err
	}

	as := database.Identity{UID: user.UID, GID: user.GID, Home: user.Home}
	err = d.server.Import(ctx, as, params.Username, params.Database, user.Home+"/"+params.Path)

	if params.Remove {
		account := files.Account{UID: user.UID, GID: user.GID, Home: user.Home}

		var result json.RawMessage
		if removeErr := d.helper.Run(ctx, account, files.OpRemove, files.PathsParams{Paths: []string{params.Path}}, nil, &result); removeErr != nil {
			slog.Warn("could not remove an imported file", "user", params.Username, "path", params.Path, "error", removeErr)
		}
	}

	var failed *database.ImportError
	if errors.As(err, &failed) {
		return nil, rpc.Errorf(rpc.CodeActionFailed, "%s", failed.Message)
	}

	if err != nil {
		return nil, databaseError(err, "import into database "+params.Database)
	}

	return struct{}{}, nil
}

// maintain implements db.maintain: check, repair, optimize or analyze the
// tables of the database, returning what MariaDB reported.
func (d databaseActions) maintain(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[maintainParams](raw)
	if err != nil {
		return nil, err
	}

	if _, err := d.accounts.requireAccount(ctx, params.Username); err != nil {
		return nil, err
	}

	lines, err := d.server.Maintain(ctx, params.Username, params.Database, params.Operation)
	if err != nil {
		return nil, databaseError(err, params.Operation+" database "+params.Database)
	}

	return struct {
		Lines []string `json:"lines"`
	}{Lines: lines}, nil
}

// setRemoteAccess implements db.set_remote_access: MariaDB answers on every
// address, or only on 127.0.0.1, restarting when that changes.
func (d databaseActions) setRemoteAccess(ctx context.Context, raw json.RawMessage) (any, error) {
	var params remoteAccessParams
	if err := rpc.DecodeParams(raw, &params); err != nil {
		return nil, err
	}

	if err := d.server.SetRemoteAccess(ctx, params.Enabled); err != nil {
		return nil, databaseError(err, "change where MariaDB listens")
	}

	return struct {
		Enabled bool `json:"enabled"`
	}{Enabled: d.server.RemoteAccess()}, nil
}
