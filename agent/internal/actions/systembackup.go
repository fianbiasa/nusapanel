package actions

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"log/slog"
	"os"
	"path/filepath"
	"sort"
	"syscall"
	"time"

	"rayxo/agent/internal/files"
	"rayxo/agent/internal/rpc"
)

const (
	// systemBackupTimeout bounds one backup of the panel itself.
	systemBackupTimeout = time.Hour
	// systemBackupFolder is the top folder of a backup of the panel.
	systemBackupFolder = "rayxo-system"
)

// SystemBackupSources are what a backup of the panel itself holds besides
// the dump of its database, by the folder each has in the backup.
type SystemBackupSources map[string]string

// DefaultSystemBackupSources are the panel's configuration (its .env with
// the key that decrypts its data, certificates), the DKIM keys mail is
// signed with, and the files people uploaded (ticket attachments, logos).
func DefaultSystemBackupSources() SystemBackupSources {
	return SystemBackupSources{
		"etc-rayxo":     "/etc/rayxo",
		"opendkim-keys": "/etc/opendkim/keys",
		"storage":       "/var/lib/rayxo/storage/app",
	}
}

// panelDumper dumps the panel's own database. database.Manager implements
// it.
type panelDumper interface {
	ExportPanel(ctx context.Context, name string, out io.Writer) error
}

// systemBackupCreateParams are the params of backup.system_create.
type systemBackupCreateParams struct {
	Name     string          `json:"name"`
	Manifest json.RawMessage `json:"manifest"`
}

func (p systemBackupCreateParams) account() string { return "" }

func (p systemBackupCreateParams) validate() error {
	errs := []error{validateBackupName(p.Name)}

	if len(p.Manifest) > maxBackupJSON || !json.Valid(p.Manifest) || !bytes.HasPrefix(bytes.TrimSpace(p.Manifest), []byte("{")) {
		errs = append(errs, fmt.Errorf("manifest must be a JSON object of at most %d bytes", maxBackupJSON))
	}

	return errors.Join(errs...)
}

// systemBackupDownloadParams name a backup of the panel and the transfer
// it is copied to.
type systemBackupDownloadParams struct {
	Name     string `json:"name"`
	Transfer string `json:"transfer"`
}

func (p systemBackupDownloadParams) account() string { return "" }

func (p systemBackupDownloadParams) validate() error {
	return errors.Join(validateBackupName(p.Name), files.CheckTransferID(p.Transfer))
}

// systemBackupActions implements the backup.system_* methods: backups of
// the panel itself, from which setup.sh brings a panel back on a new
// server. They hold secrets (the key that decrypts the panel's data, the
// DKIM keys), so only root reads them; downloads go through a transfer.
type systemBackupActions struct {
	config    BackupsConfig
	dumper    panelDumper
	transfers fileTransfers
	space     func(dir string) (diskSpace, error)
}

func systemBackupMethods(config BackupsConfig, dumper panelDumper, transfers fileTransfers) map[string]rpc.Method {
	actions := systemBackupActions{config: config, dumper: dumper, transfers: transfers, space: readDiskSpace}

	return map[string]rpc.Method{
		"backup.system_create":   {Handler: actions.create, Timeout: systemBackupTimeout},
		"backup.system_delete":   {Handler: actions.delete},
		"backup.system_download": {Handler: actions.download, Timeout: systemBackupTimeout},
	}
}

// create implements backup.system_create: <system dir>/<name> becomes a
// tar.gz of rayxo-system/ with the manifest the panel sent, a dump of the
// panel's database, and the folders of the sources. Links are kept as
// links and never followed. It stops before the disk has less than the
// reserve free, and leaves nothing behind then.
func (s systemBackupActions) create(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[systemBackupCreateParams](raw)
	if err != nil {
		return nil, err
	}

	if err := s.ensureDir(); err != nil {
		return nil, err
	}

	guard := &spaceGuard{dir: s.config.SystemDir, space: s.space}
	if err := guard.check(); err != nil {
		return nil, err
	}

	path := filepath.Join(s.config.SystemDir, params.Name)
	if _, err := os.Lstat(path); err == nil {
		return nil, rpc.Errorf(rpc.CodeActionFailed, "backup %s already exists", params.Name)
	}

	temp, err := os.CreateTemp(s.config.SystemDir, ".create-*.tmp")
	if err != nil {
		return nil, fmt.Errorf("create a backup of the panel: %w", err)
	}

	created := false

	defer func() {
		_ = temp.Close()

		if !created {
			_ = os.Remove(temp.Name())
		}
	}()

	guard.out = temp
	compressed := gzip.NewWriter(guard)
	out := tar.NewWriter(compressed)
	now := time.Now()

	if err := out.WriteHeader(&tar.Header{Typeflag: tar.TypeDir, Name: systemBackupFolder + "/", Mode: 0o700, ModTime: now}); err != nil {
		return nil, s.writeError(err)
	}

	if err := writeTarFile(out, systemBackupFolder+"/manifest.json", int64(len(params.Manifest)), bytes.NewReader(params.Manifest), now); err != nil {
		return nil, s.writeError(err)
	}

	if err := s.addDump(ctx, out, now); err != nil {
		return nil, err
	}

	names := make([]string, 0, len(s.config.SystemSources))
	for name := range s.config.SystemSources {
		names = append(names, name)
	}

	sort.Strings(names)

	for _, name := range names {
		if err := addTree(out, s.config.SystemSources[name], systemBackupFolder+"/"+name); err != nil {
			return nil, s.writeError(err)
		}
	}

	if err := errors.Join(out.Close(), compressed.Close()); err != nil {
		return nil, s.writeError(err)
	}

	if err := temp.Sync(); err != nil {
		return nil, s.writeError(err)
	}

	if err := temp.Chmod(0o600); err != nil {
		return nil, fmt.Errorf("protect backup %s: %w", params.Name, err)
	}

	if err := os.Link(temp.Name(), path); err != nil {
		return nil, fmt.Errorf("save backup %s: %w", params.Name, err)
	}

	created = true
	_ = os.Remove(temp.Name())

	info, err := os.Lstat(path)
	if err != nil {
		return nil, fmt.Errorf("read backup %s: %w", params.Name, err)
	}

	return map[string]any{"name": params.Name, "size": info.Size()}, nil
}

// addDump dumps the panel's database into a file of the system folder,
// since the archive needs to know its size, then adds it.
func (s systemBackupActions) addDump(ctx context.Context, out *tar.Writer, now time.Time) error {
	guard := &spaceGuard{dir: s.config.SystemDir, space: s.space}
	if err := guard.check(); err != nil {
		return err
	}

	dump, err := os.CreateTemp(s.config.SystemDir, ".dump-*.sql")
	if err != nil {
		return fmt.Errorf("dump the panel's database: %w", err)
	}

	defer func() {
		_ = dump.Close()
		_ = os.Remove(dump.Name())
	}()

	guard.out = dump

	if err := s.dumper.ExportPanel(ctx, s.config.PanelDatabase, guard); err != nil {
		if guard.full != nil {
			return guard.full
		}

		return databaseError(err, "dump the panel's database")
	}

	size, err := dump.Seek(0, io.SeekCurrent)
	if err == nil {
		_, err = dump.Seek(0, io.SeekStart)
	}

	if err != nil {
		return fmt.Errorf("dump the panel's database: %w", err)
	}

	if err := writeTarFile(out, systemBackupFolder+"/panel.sql", size, dump, now); err != nil {
		return s.writeError(err)
	}

	return nil
}

// delete implements backup.system_delete: the backups go for good. Those
// that are gone already count as deleted; links are refused.
func (s systemBackupActions) delete(_ context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[serverBackupDeleteParams](raw)
	if err != nil {
		return nil, err
	}

	deleted := make([]string, 0, len(params.Names))

	for _, name := range params.Names {
		path := filepath.Join(s.config.SystemDir, name)

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

// download implements backup.system_download: a copy of the backup goes
// to a download transfer, which only the panel user reads.
func (s systemBackupActions) download(_ context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[systemBackupDownloadParams](raw)
	if err != nil {
		return nil, err
	}

	source, err := os.OpenFile(filepath.Join(s.config.SystemDir, params.Name), os.O_RDONLY|syscall.O_NOFOLLOW, 0)
	if errors.Is(err, fs.ErrNotExist) {
		return nil, &rpc.Error{Code: rpc.CodeActionFailed, Message: "backup " + params.Name + " does not exist", Data: map[string]string{"reason": files.ReasonNotFound}}
	}

	if err != nil {
		return nil, fmt.Errorf("open backup %s: %w", params.Name, err)
	}
	defer source.Close()

	if info, err := source.Stat(); err != nil || !info.Mode().IsRegular() {
		return nil, rpc.Errorf(rpc.CodeActionFailed, "%s is not a backup", params.Name)
	}

	target, err := s.transfers.CreateDownload(params.Transfer)
	if err != nil {
		slog.Warn("system backup download refused", "transfer", params.Transfer, "error", err)

		return nil, rpc.Errorf(rpc.CodeActionFailed, "the download could not be prepared")
	}

	_, err = io.Copy(target, source)
	if err = errors.Join(err, target.Close()); err != nil {
		_ = os.Remove(s.transfers.DownloadPath(params.Transfer))

		return nil, fmt.Errorf("copy backup %s: %w", params.Name, err)
	}

	return struct{}{}, nil
}

// ensureDir makes the folder of the panel's backups, for root alone.
func (s systemBackupActions) ensureDir() error {
	if err := os.MkdirAll(s.config.SystemDir, 0o700); err != nil {
		return fmt.Errorf("create the folder of the panel's backups: %w", err)
	}

	info, err := os.Lstat(s.config.SystemDir)
	if err != nil {
		return fmt.Errorf("read the folder of the panel's backups: %w", err)
	}

	if !info.IsDir() {
		return fmt.Errorf("%s is not a folder", s.config.SystemDir)
	}

	if info.Mode().Perm() != 0o700 {
		return os.Chmod(s.config.SystemDir, 0o700)
	}

	return nil
}

// writeError explains a failed write: a full disk, or the error itself.
func (s systemBackupActions) writeError(err error) error {
	return serverBackupActions{}.writeError(err)
}

// writeTarFile adds the regular file name holding size bytes of content.
func writeTarFile(out *tar.Writer, name string, size int64, content io.Reader, modified time.Time) error {
	if err := out.WriteHeader(&tar.Header{Typeflag: tar.TypeReg, Name: name, Size: size, Mode: 0o600, ModTime: modified}); err != nil {
		return err
	}

	_, err := io.CopyN(out, content, size)

	return err
}

// addTree adds the folder dir, with all it holds, as folder name. A dir
// that does not exist adds nothing. It is read through an os.Root, so no
// link (also one put in place while it is read: the panel user writes some
// of these folders) leads root anywhere outside it. Links are kept as
// links, and sockets and devices are left out.
func addTree(out *tar.Writer, dir, name string) error {
	root, err := os.OpenRoot(dir)
	if errors.Is(err, fs.ErrNotExist) {
		return nil
	}

	if err != nil {
		return err
	}
	defer root.Close()

	return fs.WalkDir(root.FS(), ".", func(path string, entry fs.DirEntry, err error) error {
		if err != nil {
			return err
		}

		target := name
		if path != "." {
			target = name + "/" + path
		}

		info, err := root.Lstat(path)
		if err != nil {
			return err
		}

		header := &tar.Header{Name: target, Mode: int64(info.Mode().Perm()), ModTime: info.ModTime()}

		switch {
		case info.IsDir():
			header.Typeflag, header.Name = tar.TypeDir, target+"/"

			return out.WriteHeader(header)
		case info.Mode()&fs.ModeSymlink != 0:
			link, err := root.Readlink(path)
			if err != nil {
				return err
			}

			header.Typeflag, header.Linkname = tar.TypeSymlink, link

			return out.WriteHeader(header)
		case info.Mode().IsRegular():
			return addTreeFile(out, root, path, header)
		}

		return nil
	})
}

// addTreeFile adds one regular file of root.
func addTreeFile(out *tar.Writer, root *os.Root, path string, header *tar.Header) error {
	file, err := root.Open(path)
	if err != nil {
		return err
	}
	defer file.Close()

	info, err := file.Stat()
	if err != nil {
		return err
	}

	if !info.Mode().IsRegular() {
		return nil
	}

	header.Typeflag, header.Size = tar.TypeReg, info.Size()

	if err := out.WriteHeader(header); err != nil {
		return err
	}

	// A file that shrank while it was read is padded, as in a backup of a
	// home: the header already holds its size.
	written, err := io.Copy(out, io.LimitReader(file, info.Size()))
	if err == nil && written < info.Size() {
		_, err = io.CopyN(out, bytes.NewReader(make([]byte, info.Size()-written)), info.Size()-written)
	}

	return err
}
