package actions

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"slices"

	"rayxo/agent/internal/database"
	"rayxo/agent/internal/files"
	"rayxo/agent/internal/rpc"
)

// A cPanel backup the administrator copied into the backups folder becomes
// a RAYXO backup there, which the panel then restores like one of its own
// (see files/cpanel.go): backup.cpanel_inspect reads what it holds, and
// backup.cpanel_convert writes the RAYXO backup the panel worked out.

const maxCpanelRules = 10_000

var (
	// cpanelTopPattern is the folder a cPanel backup holds everything in.
	cpanelTopPattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{0,200}$`)
	// cpanelDatabasePattern is the name of a database in a cPanel backup.
	cpanelDatabasePattern = regexp.MustCompile(`^[A-Za-z0-9_-]{1,64}$`)
	// convertedDatabasePattern is its name here.
	convertedDatabasePattern = regexp.MustCompile(`^[a-z0-9_]{1,64}$`)
)

// cpanelDatabase is a dump of the cPanel backup, From, and the database it
// goes into here, To.
type cpanelDatabase struct {
	From string `json:"from"`
	To   string `json:"to"`
}

// cpanelConvertParams are the params of backup.cpanel_convert: the cPanel
// backup (Source, with everything under Top), the RAYXO backup it becomes
// (Name) with the manifest and settings the panel made, the dumps it holds
// and where the files of the home go.
type cpanelConvertParams struct {
	Source    string             `json:"source"`
	Name      string             `json:"name"`
	Top       string             `json:"top"`
	Manifest  json.RawMessage    `json:"manifest"`
	Settings  json.RawMessage    `json:"settings"`
	Databases []cpanelDatabase   `json:"databases"`
	Rules     []files.CpanelRule `json:"rules"`
}

func (p cpanelConvertParams) account() string { return "" }

func (p cpanelConvertParams) validate() error {
	errs := []error{validateBackupName(p.Source), validateBackupName(p.Name)}

	if p.Source == p.Name {
		errs = append(errs, errors.New("the backup must not replace the cPanel backup"))
	}

	if !cpanelTopPattern.MatchString(p.Top) {
		errs = append(errs, fmt.Errorf("top %q is not a folder name", p.Top))
	}

	for name, value := range map[string]json.RawMessage{"manifest": p.Manifest, "settings": p.Settings} {
		if len(value) > maxBackupJSON || !json.Valid(value) || !bytes.HasPrefix(bytes.TrimSpace(value), []byte("{")) {
			errs = append(errs, fmt.Errorf("%s must be a JSON object of at most %d bytes", name, maxBackupJSON))
		}
	}

	targets := make([]string, 0, len(p.Databases))

	for _, db := range p.Databases {
		if !cpanelDatabasePattern.MatchString(db.From) || !convertedDatabasePattern.MatchString(db.To) {
			errs = append(errs, fmt.Errorf("database %q cannot become %q", db.From, db.To))
		}

		targets = append(targets, db.To)
	}

	if len(slices.Compact(slices.Sorted(slices.Values(targets)))) != len(targets) {
		errs = append(errs, errors.New("a database is named twice"))
	}

	if len(p.Rules) > maxCpanelRules {
		errs = append(errs, fmt.Errorf("at most %d rules", maxCpanelRules))
	}

	for _, rule := range p.Rules {
		errs = append(errs, files.CheckCpanelRule(rule))
	}

	return errors.Join(errs...)
}

// cpanelInspect implements backup.cpanel_inspect: what a cPanel backup in
// the folder holds, read through once.
func (s serverBackupActions) cpanelInspect(_ context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[serverBackupNameParams](raw)
	if err != nil {
		return nil, err
	}

	file, err := s.open(params.Name)
	if err != nil {
		return nil, err
	}
	defer file.Close()

	inspection, err := files.ReadCpanelBackup(file)
	if err != nil {
		return nil, fileError(err)
	}

	return inspection, nil
}

// cpanelConvert implements backup.cpanel_convert: <dir>/<name> becomes a
// RAYXO backup of the cPanel backup source, with the manifest and settings
// the panel sent, the dumps (their definers made the importing user), then
// the home, each part where the rules put it. The cPanel backup stays. As
// a backup does, it stops before the disk has less than the reserve free,
// and leaves nothing behind then.
func (s serverBackupActions) cpanelConvert(_ context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[cpanelConvertParams](raw)
	if err != nil {
		return nil, err
	}

	if err := s.ensureDir(); err != nil {
		return nil, err
	}

	source, err := s.open(params.Source)
	if err != nil {
		return nil, err
	}
	defer source.Close()

	guard, err := s.guard()
	if err != nil {
		return nil, err
	}

	path := filepath.Join(s.config.Dir, params.Name)
	if _, err := os.Lstat(path); err == nil {
		return nil, rpc.Errorf(rpc.CodeActionFailed, "backup %s already exists", params.Name)
	}

	temp, err := os.CreateTemp(s.config.Dir, ".convert-*.tmp")
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

	if err := s.addCpanelMeta(out, source, params); err != nil {
		return nil, err
	}

	if _, err := source.Seek(0, io.SeekStart); err != nil {
		return nil, fmt.Errorf("read backup %s again: %w", params.Source, err)
	}

	copied, err := out.AddCpanelHome(source, params.Top, params.Rules)
	if err != nil {
		return nil, s.writeError(err)
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

	return map[string]any{
		"name":     params.Name,
		"size":     info.Size(),
		"manifest": string(params.Manifest),
		"settings": string(params.Settings),
		"entries":  copied.Entries,
		"dropped":  copied.Dropped,
	}, nil
}

// addCpanelMeta adds .rayxo-backup/ with the manifest, the settings and the
// dumps of the cPanel backup, under the names of the databases here.
func (s serverBackupActions) addCpanelMeta(out *files.BackupWriter, source io.Reader, params cpanelConvertParams) error {
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

	targets := map[string]string{}
	wanted := make([]string, 0, len(params.Databases))

	for _, db := range params.Databases {
		targets[db.From] = db.To
		wanted = append(wanted, db.From)
	}

	err := files.CopyCpanelDumps(source, params.Top, wanted, func(name string, _ int64, content io.Reader) error {
		return s.addCpanelDump(out, targets[name], content)
	})
	if err != nil {
		return s.writeError(err)
	}

	return nil
}

// addCpanelDump writes a dump into a file of the backups folder with its
// definers rewritten, since the archive needs to know its size, then adds
// it as the dump of database name.
func (s serverBackupActions) addCpanelDump(out *files.BackupWriter, name string, content io.Reader) error {
	guard, err := s.guard()
	if err != nil {
		return err
	}

	dump, err := os.CreateTemp(s.config.Dir, ".dump-*.sql")
	if err != nil {
		return fmt.Errorf("copy the dump of %s: %w", name, err)
	}

	defer func() {
		_ = dump.Close()
		_ = os.Remove(dump.Name())
	}()

	guard.out = dump

	if err := database.RewriteDefiners(content, guard); err != nil {
		return s.writeError(err)
	}

	size, err := dump.Seek(0, io.SeekCurrent)
	if err == nil {
		_, err = dump.Seek(0, io.SeekStart)
	}

	if err != nil {
		return fmt.Errorf("copy the dump of %s: %w", name, err)
	}

	if err := out.AddFile(files.BackupFolder+"/databases/"+name+".sql", size, dump); err != nil {
		return s.writeError(err)
	}

	return nil
}
