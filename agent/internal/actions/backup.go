package actions

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"os"
	"regexp"
	"slices"
	"time"

	"rayxo/agent/internal/database"
	"rayxo/agent/internal/files"
	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/validate"
)

const (
	// backupTimeout bounds one backup or restore of a large account.
	backupTimeout = 6 * time.Hour
	// backupDir is the folder of the home backups are kept in.
	backupDir = "backups"
	// restoreDir is where a restore unpacks the dumps of databases.
	restoreDir = ".rayxo-restore"
	// maxBackupJSON bounds the manifest and the settings the panel sends.
	maxBackupJSON = 8 << 20
	// mailDir is the folder of the home that holds the account's mail.
	mailDir = "imap"
)

// backupSkip are the folders of a home the files of a backup leave out:
// the backups themselves, the trash, temporary files, the mail (an item of
// its own) and the backup's working folders.
var backupSkip = []string{backupDir, ".trash", "tmp", mailDir, files.BackupFolder, restoreDir}

var backupNamePattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{0,150}\.tar\.gz$`)

// backupCreateParams are the params of backup.create: which parts of the
// account a backup holds, and what the panel adds about it.
type backupCreateParams struct {
	Username  string          `json:"username"`
	Name      string          `json:"name"`
	Files     bool            `json:"files"`
	Email     bool            `json:"email"`
	Databases []string        `json:"databases"`
	Manifest  json.RawMessage `json:"manifest"`
	Settings  json.RawMessage `json:"settings"`
}

func (p backupCreateParams) account() string { return p.Username }

func (p backupCreateParams) validate() error {
	errs := []error{validate.Username(p.Username), validateBackupName(p.Name), validateBackupDatabases(p.Username, p.Databases)}

	for name, value := range map[string]json.RawMessage{"manifest": p.Manifest, "settings": p.Settings} {
		if len(value) > maxBackupJSON || !json.Valid(value) || !bytes.HasPrefix(bytes.TrimSpace(value), []byte("{")) {
			errs = append(errs, fmt.Errorf("%s must be a JSON object of at most %d bytes", name, maxBackupJSON))
		}
	}

	return errors.Join(errs...)
}

// backupInspectParams name a backup of the account.
type backupInspectParams struct {
	Username string `json:"username"`
	Name     string `json:"name"`
}

func (p backupInspectParams) account() string { return p.Username }

func (p backupInspectParams) validate() error {
	return errors.Join(validate.Username(p.Username), validateBackupName(p.Name))
}

// backupRestoreParams are the params of backup.restore: which parts of a
// backup go back into the account. The databases must exist.
type backupRestoreParams struct {
	Username  string   `json:"username"`
	Name      string   `json:"name"`
	Files     bool     `json:"files"`
	Email     bool     `json:"email"`
	Databases []string `json:"databases"`
}

func (p backupRestoreParams) account() string { return p.Username }

func (p backupRestoreParams) validate() error {
	return errors.Join(validate.Username(p.Username), validateBackupName(p.Name), validateBackupDatabases(p.Username, p.Databases))
}

func validateBackupName(name string) error {
	if !backupNamePattern.MatchString(name) {
		return fmt.Errorf("backup name %q must be letters, digits, dots, dashes and underscores ending in .tar.gz", name)
	}

	return nil
}

func validateBackupDatabases(username string, names []string) error {
	errs := make([]error, 0, len(names))

	for _, name := range names {
		errs = append(errs, database.ValidateName(username, name, database.MaxDatabaseName))
	}

	if len(slices.Compact(slices.Sorted(slices.Values(names)))) != len(names) {
		errs = append(errs, errors.New("a database is named twice"))
	}

	return errors.Join(errs...)
}

// backupActions implements the backup.* methods: an account's backups in
// its own ~/backups. The agent never touches the home itself: the files
// helper packs and unpacks as the account, and dumps go back into
// MariaDB as the account's owner user (see database.Import).
type backupActions struct {
	accounts  accountActions
	helper    fileHelper
	databases databaseServer
}

func backupMethods(system accountSystem, helper fileHelper, databases databaseServer) map[string]rpc.Method {
	actions := backupActions{accounts: accountActions{system: system}, helper: helper, databases: databases}

	return map[string]rpc.Method{
		"backup.create":  {Handler: actions.create, LockKey: webLockKey[backupCreateParams], Timeout: backupTimeout},
		"backup.inspect": {Handler: actions.inspect},
		"backup.delete":  {Handler: actions.delete, LockKey: webLockKey[backupInspectParams]},
		"backup.restore": {Handler: actions.restore, LockKey: webLockKey[backupRestoreParams], Timeout: backupTimeout},
	}
}

// create implements backup.create: ~/backups/<name> becomes a tar.gz with
// the manifest and settings the panel sent and the dumps of the databases
// in .rayxo-backup/, then the files of the home and its mail as asked.
func (b backupActions) create(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[backupCreateParams](raw)
	if err != nil {
		return nil, err
	}

	account, err := b.account(ctx, params.Username)
	if err != nil {
		return nil, err
	}

	defer b.remove(ctx, account, files.BackupFolder)

	b.remove(ctx, account, files.BackupFolder)

	for _, folder := range []string{backupDir, files.BackupFolder, files.BackupFolder + "/databases"} {
		if err := b.mkdir(ctx, account, folder); err != nil {
			return nil, fileError(err)
		}
	}

	if err := b.write(ctx, account, files.BackupManifest, bytes.NewReader(params.Manifest)); err != nil {
		return nil, fileError(err)
	}

	if err := b.write(ctx, account, files.BackupSettings, bytes.NewReader(params.Settings)); err != nil {
		return nil, fileError(err)
	}

	for _, name := range params.Databases {
		if err := b.dump(ctx, account, params.Username, name); err != nil {
			return nil, err
		}
	}

	var listing files.Listing
	if err := b.helper.Run(ctx, account, files.OpList, files.PathParams{Path: ""}, nil, &listing); err != nil {
		return nil, fileError(err)
	}

	paths := []string{files.BackupFolder}

	for _, entry := range listing.Entries {
		switch {
		case entry.Name == mailDir && params.Email:
			paths = append(paths, entry.Name)
		case params.Files && !slices.Contains(backupSkip, entry.Name):
			paths = append(paths, entry.Name)
		}
	}

	var packed json.RawMessage
	if err := b.helper.Run(ctx, account, files.OpBackupPack, files.BackupPackParams{Paths: paths, Destination: backupDir + "/" + params.Name}, nil, &packed); err != nil {
		return nil, fileError(err)
	}

	return packed, nil
}

// inspect implements backup.inspect: the manifest and the settings of a
// backup, which are at its start.
func (b backupActions) inspect(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[backupInspectParams](raw)
	if err != nil {
		return nil, err
	}

	account, err := b.account(ctx, params.Username)
	if err != nil {
		return nil, err
	}

	var meta json.RawMessage
	if err := b.helper.Run(ctx, account, files.OpBackupMeta, files.PathParams{Path: backupDir + "/" + params.Name}, nil, &meta); err != nil {
		return nil, fileError(err)
	}

	return meta, nil
}

// delete implements backup.delete: the backup goes for good, not to the
// trash, so its space is free at once.
func (b backupActions) delete(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[backupInspectParams](raw)
	if err != nil {
		return nil, err
	}

	account, err := b.account(ctx, params.Username)
	if err != nil {
		return nil, err
	}

	var result struct {
		Failed []struct {
			Message string `json:"message"`
		} `json:"failed"`
	}

	if err := b.helper.Run(ctx, account, files.OpRemove, files.PathsParams{Paths: []string{backupDir + "/" + params.Name}}, nil, &result); err != nil {
		return nil, fileError(err)
	}

	if len(result.Failed) > 0 {
		return nil, rpc.Errorf(rpc.CodeActionFailed, "%s", result.Failed[0].Message)
	}

	return struct{}{}, nil
}

// backupRestored is what a restore put back.
type backupRestored struct {
	Files     *files.ExtractResult `json:"files,omitempty"`
	Databases []string             `json:"databases"`
}

// restore implements backup.restore: the files and the mail of the backup
// replace those of the home (files it does not hold stay), and the dumps
// of the databases are run in them.
func (b backupActions) restore(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[backupRestoreParams](raw)
	if err != nil {
		return nil, err
	}

	user, err := b.accounts.requireAccount(ctx, params.Username)
	if err != nil {
		return nil, err
	}

	account := files.Account{UID: user.UID, GID: user.GID, Home: user.Home}
	archive := backupDir + "/" + params.Name
	restored := backupRestored{Databases: []string{}}

	if params.Files || params.Email {
		extract := files.BackupExtractParams{Archive: archive, Exclude: []string{files.BackupFolder, backupDir, restoreDir}}

		switch {
		case !params.Files:
			extract.Include = []string{mailDir}
		case !params.Email:
			extract.Exclude = append(extract.Exclude, mailDir)
		}

		var result files.ExtractResult
		if err := b.helper.Run(ctx, account, files.OpBackupExtract, extract, nil, &result); err != nil {
			return nil, fileError(err)
		}

		restored.Files = &result
	}

	if len(params.Databases) == 0 {
		return restored, nil
	}

	defer b.remove(ctx, account, restoreDir)

	b.remove(ctx, account, restoreDir)

	var unpacked json.RawMessage
	if err := b.helper.Run(ctx, account, files.OpBackupExtract, files.BackupExtractParams{Archive: archive, Destination: restoreDir, Include: []string{files.BackupFolder}}, nil, &unpacked); err != nil {
		return nil, fileError(err)
	}

	as := database.Identity{UID: user.UID, GID: user.GID, Home: user.Home}

	for _, name := range params.Databases {
		dump := user.Home + "/" + restoreDir + "/" + files.BackupFolder + "/databases/" + name + ".sql"

		err := b.databases.Import(ctx, as, params.Username, name, dump)

		var failed *database.ImportError

		switch {
		case errors.As(err, &failed):
			return nil, rpc.Errorf(rpc.CodeActionFailed, "database %s: %s", name, failed.Message)
		case err != nil:
			return nil, databaseError(err, "restore database "+name)
		}

		restored.Databases = append(restored.Databases, name)
	}

	return restored, nil
}

// account returns the home a call acts in.
func (b backupActions) account(ctx context.Context, name string) (files.Account, error) {
	user, err := b.accounts.requireAccount(ctx, name)
	if err != nil {
		return files.Account{}, err
	}

	return files.Account{UID: user.UID, GID: user.GID, Home: user.Home}, nil
}

// dump writes the dump of database name into the working folder.
func (b backupActions) dump(ctx context.Context, account files.Account, username, name string) error {
	reader, writer, err := os.Pipe()
	if err != nil {
		return systemError(err)
	}

	exported := make(chan error, 1)

	go func() {
		err := b.databases.Export(ctx, username, name, writer)
		exported <- errors.Join(err, writer.Close())
	}()

	written := b.helper.Run(ctx, account, files.OpUpload, files.UploadParams{Path: files.BackupFolder + "/databases/" + name + ".sql", Overwrite: true}, reader, nil)
	_ = reader.Close()

	if err := <-exported; err != nil {
		return databaseError(err, "dump database "+name)
	}

	if written != nil {
		return fileError(written)
	}

	return nil
}

// write writes content to path in the home, as the account.
func (b backupActions) write(ctx context.Context, account files.Account, path string, content io.Reader) error {
	reader, writer, err := os.Pipe()
	if err != nil {
		return err
	}

	copied := make(chan error, 1)

	go func() {
		_, err := io.Copy(writer, content)
		copied <- errors.Join(err, writer.Close())
	}()

	err = b.helper.Run(ctx, account, files.OpUpload, files.UploadParams{Path: path, Overwrite: true}, reader, nil)
	_ = reader.Close()

	return errors.Join(err, <-copied)
}

// mkdir makes folder in the home unless it exists.
func (b backupActions) mkdir(ctx context.Context, account files.Account, folder string) error {
	err := b.helper.Run(ctx, account, files.OpMkdir, files.PathParams{Path: folder}, nil, nil)

	var failure *files.Error
	if errors.As(err, &failure) && failure.Reason == files.ReasonExists {
		return nil
	}

	return err
}

// remove deletes a working folder for good; a failure is only logged.
func (b backupActions) remove(ctx context.Context, account files.Account, folder string) {
	var result json.RawMessage
	if err := b.helper.Run(context.WithoutCancel(ctx), account, files.OpRemove, files.PathsParams{Paths: []string{folder}}, nil, &result); err != nil {
		slog.Warn("could not remove a backup working folder", "home", account.Home, "folder", folder, "error", err)
	}
}
