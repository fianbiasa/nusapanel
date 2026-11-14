package actions

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"log/slog"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"syscall"

	"golang.org/x/sys/unix"

	"rayxo/agent/internal/database"
	"rayxo/agent/internal/files"
	"rayxo/agent/internal/rpc"
)

const (
	// minBackupReserve and backupReservePercent are the space the backups
	// the agent keeps always leave free on their disk: the larger of the
	// two, so websites, mail and MariaDB keep room to write.
	minBackupReserve     = 2 << 30
	backupReservePercent = 5
	// spaceCheckBytes is how often, in bytes written, a backup looks at the
	// free space again.
	spaceCheckBytes = 64 << 20
	// reasonNoSpace is the reason of an error the panel shows as "not
	// enough free space".
	reasonNoSpace = "no_space"
)

// BackupsConfig is where the agent keeps the backups the panel makes of
// accounts, outside their homes.
type BackupsConfig struct {
	// Dir holds the backups. It and they belong to root; the panel's group
	// may read them, for downloads.
	Dir string
	// PanelGID is the panel's group, or -1.
	PanelGID int
}

// diskSpace is the size of a filesystem and what of it is free.
type diskSpace struct {
	Total uint64 `json:"total"`
	Free  uint64 `json:"free"`
	// Reserve is what backups always leave free.
	Reserve uint64 `json:"reserve"`
}

// readDiskSpace reads the space of the filesystem dir is on. Free is what
// users other than root may still use.
func readDiskSpace(dir string) (diskSpace, error) {
	var stat unix.Statfs_t
	if err := unix.Statfs(dir, &stat); err != nil {
		return diskSpace{}, fmt.Errorf("read the free space of %s: %w", dir, err)
	}

	size := uint64(stat.Bsize)
	space := diskSpace{Total: stat.Blocks * size, Free: stat.Bavail * size}
	space.Reserve = max(minBackupReserve, space.Total*backupReservePercent/100)

	return space, nil
}

// serverBackupNameParams name one backup the agent keeps.
type serverBackupNameParams struct {
	Name string `json:"name"`
}

func (p serverBackupNameParams) account() string { return "" }

func (p serverBackupNameParams) validate() error { return validateBackupName(p.Name) }

// serverBackupDeleteParams name backups the agent keeps.
type serverBackupDeleteParams struct {
	Names []string `json:"names"`
}

func (p serverBackupDeleteParams) account() string { return "" }

func (p serverBackupDeleteParams) validate() error {
	if len(p.Names) == 0 || len(p.Names) > 1000 {
		return errors.New("name between 1 and 1000 backups")
	}

	errs := make([]error, 0, len(p.Names))
	for _, name := range p.Names {
		errs = append(errs, validateBackupName(name))
	}

	return errors.Join(errs...)
}

// serverBackupCreated is what backup.server_create made.
type serverBackupCreated struct {
	Name string `json:"name"`
	Size int64  `json:"size"`
	// Skipped counts the files of the home left out because they were
	// deleted while it was read, or the account itself may not read them.
	Skipped int `json:"skipped"`
}

// serverBackupActions implements the backup.server_* methods: backups of
// accounts the agent keeps in its own folder, so they never count toward
// an account's quota and the account cannot change them. The files of the
// home are still only read and written by the files helper, as the
// account: they reach the agent and go back as a tar stream.
type serverBackupActions struct {
	backups backupActions
	config  BackupsConfig
	space   func(dir string) (diskSpace, error)
}

func serverBackupMethods(system accountSystem, helper fileHelper, databases databaseServer, config BackupsConfig) map[string]rpc.Method {
	actions := serverBackupActions{
		backups: backupActions{accounts: accountActions{system: system}, helper: helper, databases: databases},
		config:  config,
		space:   readDiskSpace,
	}

	return map[string]rpc.Method{
		// Backups only read the account, so they take no lock: tasks for
		// the account do not wait for a backup of hours.
		"backup.server_create":  {Handler: actions.create, Timeout: backupTimeout},
		"backup.server_inspect": {Handler: actions.inspect},
		"backup.server_restore": {Handler: actions.restore, LockKey: webLockKey[backupRestoreParams], Timeout: backupTimeout},
		"backup.server_delete":  {Handler: actions.delete},
		"backup.server_space":   {Handler: actions.diskSpace},
	}
}

// create implements backup.server_create: <dir>/<name> becomes a tar.gz
// with the manifest and settings the panel sent and the dumps of the
// databases in .rayxo-backup/, then the files of the home and its mail as
// asked, like the backups accounts make of themselves. It stops before the
// disk has less than the reserve free, and leaves nothing behind then.
func (s serverBackupActions) create(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[backupCreateParams](raw)
	if err != nil {
		return nil, err
	}

	account, err := s.backups.account(ctx, params.Username)
	if err != nil {
		return nil, err
	}

	if err := s.ensureDir(); err != nil {
		return nil, err
	}

	guard, err := s.guard()
	if err != nil {
		return nil, err
	}

	path := filepath.Join(s.config.Dir, params.Name)
	if _, err := os.Lstat(path); err == nil {
		return nil, rpc.Errorf(rpc.CodeActionFailed, "backup %s already exists", params.Name)
	}

	temp, err := os.CreateTemp(s.config.Dir, ".create-*.tmp")
	if err != nil {
		return nil, fmt.Errorf("create a backup: %w", err)
	}

	created := false

	defer func() {
		_ = temp.Close()

		if !created {
			_ = os.Remove(temp.Name())
		}
	}()

	guard.out = temp
	out := files.NewBackupWriter(guard)

	if err := s.addMeta(ctx, out, params); err != nil {
		return nil, err
	}

	paths, err := s.homePaths(ctx, account, params.Files, params.Email)
	if err != nil {
		return nil, err
	}

	var streamed files.BackupStreamed

	if len(paths) > 0 {
		if streamed, err = s.addHome(ctx, account, out, paths); err != nil {
			return nil, err
		}
	}

	if err := out.Close(); err != nil {
		return nil, s.writeError(err)
	}

	if err := s.finish(temp, path); err != nil {
		return nil, err
	}

	created = true

	info, err := os.Lstat(path)
	if err != nil {
		return nil, fmt.Errorf("read backup %s: %w", params.Name, err)
	}

	return serverBackupCreated{Name: params.Name, Size: info.Size(), Skipped: streamed.Skipped}, nil
}

// addMeta adds .rayxo-backup/ with the manifest, the settings and a dump
// of every database.
func (s serverBackupActions) addMeta(ctx context.Context, out *files.BackupWriter, params backupCreateParams) error {
	if err := out.AddFolder(files.BackupFolder); err != nil {
		return s.writeError(err)
	}

	for _, meta := range []struct {
		name    string
		content []byte
	}{{files.BackupManifest, params.Manifest}, {files.BackupSettings, params.Settings}} {
		if err := out.AddFile(meta.name, int64(len(meta.content)), bytes.NewReader(meta.content)); err != nil {
			return s.writeError(err)
		}
	}

	if len(params.Databases) == 0 {
		return nil
	}

	if err := out.AddFolder(files.BackupFolder + "/databases"); err != nil {
		return s.writeError(err)
	}

	for _, name := range params.Databases {
		if err := s.addDump(ctx, out, params.Username, name); err != nil {
			return err
		}
	}

	return nil
}

// addDump dumps database name into a file of the backups folder, since
// the archive needs to know its size, then adds it.
func (s serverBackupActions) addDump(ctx context.Context, out *files.BackupWriter, username, name string) error {
	guard, err := s.guard()
	if err != nil {
		return err
	}

	dump, err := os.CreateTemp(s.config.Dir, ".dump-*.sql")
	if err != nil {
		return fmt.Errorf("dump database %s: %w", name, err)
	}

	defer func() {
		_ = dump.Close()
		_ = os.Remove(dump.Name())
	}()

	guard.out = dump

	if err := s.backups.databases.Export(ctx, username, name, guard); err != nil {
		if guard.full != nil {
			return guard.full
		}

		return databaseError(err, "dump database "+name)
	}

	size, err := dump.Seek(0, io.SeekCurrent)
	if err == nil {
		_, err = dump.Seek(0, io.SeekStart)
	}

	if err != nil {
		return fmt.Errorf("dump database %s: %w", name, err)
	}

	if err := out.AddFile(files.BackupFolder+"/databases/"+name+".sql", size, dump); err != nil {
		return s.writeError(err)
	}

	return nil
}

// homePaths are the top folders and files of the home a backup holds.
func (s serverBackupActions) homePaths(ctx context.Context, account files.Account, withFiles, withEmail bool) ([]string, error) {
	if !withFiles && !withEmail {
		return nil, nil
	}

	var listing files.Listing
	if err := s.backups.helper.Run(ctx, account, files.OpList, files.PathParams{Path: ""}, nil, &listing); err != nil {
		return nil, fileError(err)
	}

	var paths []string

	for _, entry := range listing.Entries {
		switch {
		case entry.Name == mailDir:
			if withEmail {
				paths = append(paths, entry.Name)
			}
		case withFiles && !slices.Contains(backupSkip, entry.Name):
			paths = append(paths, entry.Name)
		}
	}

	return paths, nil
}

// addHome has the files helper send paths of the home, as the account,
// and copies what it sends into the backup.
func (s serverBackupActions) addHome(ctx context.Context, account files.Account, out *files.BackupWriter, paths []string) (files.BackupStreamed, error) {
	var streamed files.BackupStreamed

	reader, writer, err := os.Pipe()
	if err != nil {
		return streamed, systemError(err)
	}

	added := make(chan error, 1)

	go func() {
		err := out.AddHome(reader)
		if err == nil {
			_, err = io.Copy(io.Discard, reader)
		}

		// Closing the reading end makes a helper still writing stop.
		_ = reader.Close()
		added <- err
	}()

	sent := s.backups.helper.Run(ctx, account, files.OpBackupStream, files.BackupStreamParams{Paths: paths}, writer, &streamed)
	_ = writer.Close()

	if err := <-added; err != nil {
		return streamed, s.writeError(err)
	}

	if sent != nil {
		return streamed, fileError(sent)
	}

	return streamed, nil
}

// finish makes the written temp file backup path, readable by root and the
// panel's group only. It never replaces a file.
func (s serverBackupActions) finish(temp *os.File, path string) error {
	if err := temp.Sync(); err != nil {
		return s.writeError(err)
	}

	if err := temp.Chmod(0o640); err != nil {
		return fmt.Errorf("protect backup %s: %w", path, err)
	}

	if os.Geteuid() == 0 && s.config.PanelGID >= 0 {
		if err := temp.Chown(0, s.config.PanelGID); err != nil {
			return fmt.Errorf("give backup %s to the panel's group: %w", path, err)
		}
	}

	if err := os.Link(temp.Name(), path); err != nil {
		return fmt.Errorf("save backup %s: %w", path, err)
	}

	return os.Remove(temp.Name())
}

// inspect implements backup.server_inspect: the manifest and the settings
// of a backup, which are at its start.
func (s serverBackupActions) inspect(_ context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[serverBackupNameParams](raw)
	if err != nil {
		return nil, err
	}

	file, err := s.open(params.Name)
	if err != nil {
		return nil, err
	}
	defer file.Close()

	meta, err := files.ReadBackupMeta(file)
	if err != nil {
		return nil, fileError(err)
	}

	return meta, nil
}

// restore implements backup.server_restore: like backup.restore, the files
// and the mail of the backup replace those of the home (files it does not
// hold stay) and the dumps of the databases are run in them. The agent
// reads the backup and sends the parts asked for to the files helper,
// which writes them as the account.
func (s serverBackupActions) restore(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[backupRestoreParams](raw)
	if err != nil {
		return nil, err
	}

	user, err := s.backups.accounts.requireAccount(ctx, params.Username)
	if err != nil {
		return nil, err
	}

	account := files.Account{UID: user.UID, GID: user.GID, Home: user.Home}
	restored := backupRestored{Databases: []string{}}

	if len(params.Databases) > 0 {
		defer s.backups.remove(ctx, account, restoreDir)

		s.backups.remove(ctx, account, restoreDir)
	}

	dumps := map[string]bool{}
	for _, name := range params.Databases {
		dumps[files.BackupFolder+"/databases/"+name+".sql"] = true
	}

	pick := func(name string) (string, bool) {
		top, _, _ := strings.Cut(name, "/")

		switch top {
		case files.BackupFolder:
			return restoreDir + "/" + name, dumps[name]
		case backupDir, restoreDir:
			return "", false
		case mailDir:
			return name, params.Email
		}

		return name, params.Files
	}

	result, err := s.unpack(ctx, account, params.Name, !params.Files && !params.Email, pick)
	if err != nil {
		return nil, err
	}

	if params.Files || params.Email {
		restored.Files = &result
	}

	as := database.Identity{UID: user.UID, GID: user.GID, Home: user.Home}

	for _, name := range params.Databases {
		dump := user.Home + "/" + restoreDir + "/" + files.BackupFolder + "/databases/" + name + ".sql"

		err := s.backups.databases.Import(ctx, as, params.Username, name, dump)

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

// unpack sends the entries of backup name that pick takes to the files
// helper, which extracts them into the home as the account.
func (s serverBackupActions) unpack(ctx context.Context, account files.Account, name string, metaOnly bool, pick func(string) (string, bool)) (files.ExtractResult, error) {
	var result files.ExtractResult

	file, err := s.open(name)
	if err != nil {
		return result, err
	}
	defer file.Close()

	reader, writer, err := os.Pipe()
	if err != nil {
		return result, systemError(err)
	}

	copied := make(chan error, 1)

	go func() {
		err := files.CopyBackupEntries(file, writer, metaOnly, pick)
		copied <- errors.Join(err, writer.Close())
	}()

	unpacked := s.backups.helper.Run(ctx, account, files.OpBackupUnpack, files.BackupUnpackParams{}, reader, &result)
	_ = reader.Close()

	read := <-copied

	if unpacked != nil {
		return result, fileError(unpacked)
	}

	if read != nil {
		return result, fileError(read)
	}

	return result, nil
}

// delete implements backup.server_delete: the backups go for good. Those
// that are gone already count as deleted.
func (s serverBackupActions) delete(_ context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[serverBackupDeleteParams](raw)
	if err != nil {
		return nil, err
	}

	deleted := make([]string, 0, len(params.Names))

	for _, name := range params.Names {
		path := filepath.Join(s.config.Dir, name)

		info, err := os.Lstat(path)

		switch {
		case errors.Is(err, fs.ErrNotExist):
			deleted = append(deleted, name)

			continue
		case err != nil:
			return nil, fmt.Errorf("delete backup %s: %w", name, err)
		case !info.Mode().IsRegular():
			return nil, rpc.Errorf(rpc.CodeActionFailed, "%s is not a backup", name)
		}

		if err := os.Remove(path); err != nil && !errors.Is(err, fs.ErrNotExist) {
			return nil, fmt.Errorf("delete backup %s: %w", name, err)
		}

		deleted = append(deleted, name)
	}

	return map[string][]string{"deleted": deleted}, nil
}

// diskSpace implements backup.server_space: the size and the free space of
// the backups' disk, and what backups always leave free on it.
func (s serverBackupActions) diskSpace(_ context.Context, raw json.RawMessage) (any, error) {
	if err := rpc.DecodeParams(raw, &struct{}{}); err != nil {
		return nil, err
	}

	if err := s.ensureDir(); err != nil {
		return nil, err
	}

	return s.space(s.config.Dir)
}

// ensureDir makes the backups folder, for root and the panel's group.
func (s serverBackupActions) ensureDir() error {
	if err := os.MkdirAll(s.config.Dir, 0o750); err != nil {
		return fmt.Errorf("create the backups folder: %w", err)
	}

	info, err := os.Lstat(s.config.Dir)
	if err != nil {
		return fmt.Errorf("read the backups folder: %w", err)
	}

	if !info.IsDir() {
		return fmt.Errorf("%s is not a folder", s.config.Dir)
	}

	if os.Geteuid() != 0 {
		return nil
	}

	if stat, ok := info.Sys().(*syscall.Stat_t); ok && (stat.Uid != 0 || (s.config.PanelGID >= 0 && int(stat.Gid) != s.config.PanelGID)) {
		if err := os.Chown(s.config.Dir, 0, s.config.PanelGID); err != nil {
			return fmt.Errorf("give the backups folder to root: %w", err)
		}
	}

	if info.Mode().Perm() != 0o750 {
		return os.Chmod(s.config.Dir, 0o750)
	}

	return nil
}

// open opens backup name for reading. It never follows a link.
func (s serverBackupActions) open(name string) (*os.File, error) {
	file, err := os.OpenFile(filepath.Join(s.config.Dir, name), os.O_RDONLY|syscall.O_NOFOLLOW, 0)
	if errors.Is(err, fs.ErrNotExist) {
		return nil, &rpc.Error{Code: rpc.CodeActionFailed, Message: "backup " + name + " does not exist", Data: map[string]string{"reason": files.ReasonNotFound}}
	}

	if err != nil {
		return nil, fmt.Errorf("open backup %s: %w", name, err)
	}

	info, err := file.Stat()
	if err == nil && !info.Mode().IsRegular() {
		err = fmt.Errorf("%s is not a file", name)
	}

	if err != nil {
		_ = file.Close()

		return nil, fmt.Errorf("open backup %s: %w", name, err)
	}

	return file, nil
}

// guard returns a writer that stops before the backups' disk has less
// than its reserve free; it refuses at once when it already has.
func (s serverBackupActions) guard() (*spaceGuard, error) {
	guard := &spaceGuard{dir: s.config.Dir, space: s.space}

	if err := guard.check(); err != nil {
		return nil, err
	}

	return guard, nil
}

// writeError explains a failed write: a full disk, or the error itself.
func (s serverBackupActions) writeError(err error) error {
	var known *rpc.Error
	if errors.As(err, &known) {
		return known
	}

	if errors.Is(err, syscall.ENOSPC) {
		return noSpaceError("the disk of the backups is full")
	}

	var failure *files.Error
	if errors.As(err, &failure) {
		return fileError(err)
	}

	slog.Error("could not write a backup", "error", err)

	return rpc.Errorf(rpc.CodeActionFailed, "could not write the backup: %v", err)
}

func noSpaceError(message string) *rpc.Error {
	return &rpc.Error{Code: rpc.CodeActionFailed, Message: message, Data: map[string]string{"reason": reasonNoSpace}}
}

// spaceGuard writes to out, and fails once the disk of dir has less than
// its reserve free. It looks again every spaceCheckBytes.
type spaceGuard struct {
	out     io.Writer
	dir     string
	space   func(dir string) (diskSpace, error)
	written int64
	full    *rpc.Error
}

func (g *spaceGuard) Write(p []byte) (int, error) {
	if g.full != nil {
		return 0, g.full
	}

	if g.written += int64(len(p)); g.written >= spaceCheckBytes {
		g.written = 0

		if err := g.check(); err != nil {
			return 0, err
		}
	}

	return g.out.Write(p)
}

func (g *spaceGuard) check() error {
	space, err := g.space(g.dir)
	if err != nil {
		return err
	}

	if space.Free < space.Reserve {
		g.full = noSpaceError(fmt.Sprintf("the disk of the backups has %.1f GB free; backups always leave %.1f GB", gib(space.Free), gib(space.Reserve)))

		return g.full
	}

	return nil
}

func gib(bytes uint64) float64 {
	return float64(bytes) / (1 << 30)
}
