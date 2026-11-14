package actions

import (
	"archive/tar"
	"compress/gzip"
	"context"
	"encoding/json"
	"errors"
	"io"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"rayxo/agent/internal/database"
	"rayxo/agent/internal/files"
	"rayxo/agent/internal/rpc"
)

// homeHelper runs the files helper's operations in this process, in a
// folder that stands for the account's home.
type homeHelper struct {
	homes map[string]string
	ops   []string
}

func (h *homeHelper) Run(_ context.Context, account files.Account, op string, params any, data *os.File, result any) error {
	h.ops = append(h.ops, op)

	raw, err := json.Marshal(params)
	if err != nil {
		return err
	}

	out, err := files.Execute(h.homes[account.Home], op, raw, data)
	if err != nil || result == nil {
		return err
	}

	encoded, _ := json.Marshal(out)

	return json.Unmarshal(encoded, result)
}

// dumpReader is a database server whose imports read the dump from the
// home, as MariaDB does.
type dumpReader struct {
	*fakeDatabaseServer
	homes    map[string]string
	imported map[string]string
}

func (d *dumpReader) Import(ctx context.Context, as database.Identity, user, name, file string) error {
	content, err := os.ReadFile(d.homes[as.Home] + strings.TrimPrefix(file, as.Home))
	if err != nil {
		return err
	}

	d.imported[name] = string(content)

	return d.fakeDatabaseServer.Import(ctx, as, user, name, file)
}

type serverBackupEnv struct {
	methods   map[string]rpc.Method
	home      string
	dir       string
	helper    *homeHelper
	databases *dumpReader
	transfers files.Transfers
	free      uint64
}

func newServerBackupEnv(t *testing.T) *serverBackupEnv {
	t.Helper()

	env := &serverBackupEnv{home: t.TempDir(), dir: filepath.Join(t.TempDir(), "backups"), free: 100 << 30}
	homes := map[string]string{"/home/budi": env.home}
	env.helper = &homeHelper{homes: homes}
	env.databases = &dumpReader{fakeDatabaseServer: &fakeDatabaseServer{}, homes: homes, imported: map[string]string{}}

	env.transfers = files.Transfers{Dir: t.TempDir(), PanelUID: os.Getuid()}

	actions := serverBackupActions{
		backups:   backupActions{accounts: accountActions{system: newQuotaSystem()}, helper: env.helper, databases: env.databases},
		config:    BackupsConfig{Dir: env.dir, PanelGID: -1},
		transfers: env.transfers,
		space: func(string) (diskSpace, error) {
			return diskSpace{Total: 200 << 30, Free: env.free, Reserve: 10 << 30}, nil
		},
	}

	env.methods = map[string]rpc.Method{
		"backup.server_create":  {Handler: actions.create},
		"backup.server_inspect": {Handler: actions.inspect},
		"backup.server_restore": {Handler: actions.restore},
		"backup.server_delete":  {Handler: actions.delete},
		"backup.server_space":   {Handler: actions.diskSpace},
		"backup.server_import":  {Handler: actions.importUpload},
		"backup.server_list":    {Handler: actions.list},
	}

	for path, content := range map[string]string{
		"domains/budi.test/public_html/index.php": "<?php echo 1;",
		"imap/budi.test/info/cur/1":               "Subject: hi\n",
		"backups/budi-old.tar.gz":                 "own backup",
		"tmp/session":                             "temporary",
		".rayxo-backup/manifest.json":             `{"forged":true}`,
	} {
		writeHomeFile(t, filepath.Join(env.home, path), content)
	}

	return env
}

func writeHomeFile(t *testing.T, path, content string) {
	t.Helper()

	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		t.Fatal(err)
	}

	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
}

func (env *serverBackupEnv) create(t *testing.T, params map[string]any) (serverBackupCreated, error) {
	t.Helper()

	base := map[string]any{"username": "budi", "name": "budi-2026-11-14-020000.tar.gz", "manifest": map[string]any{"format": 1}, "settings": map[string]any{"mailboxes": []any{}}}
	for key, value := range params {
		base[key] = value
	}

	raw, err := callBackup(env.methods, "backup.server_create", base)
	if err != nil {
		return serverBackupCreated{}, err
	}

	var created serverBackupCreated

	return created, json.Unmarshal(raw, &created)
}

func archiveEntries(t *testing.T, path string) map[string]string {
	t.Helper()

	file, err := os.Open(path)
	if err != nil {
		t.Fatal(err)
	}
	defer file.Close()

	compressed, err := gzip.NewReader(file)
	if err != nil {
		t.Fatal(err)
	}

	entries := map[string]string{}
	reader := tar.NewReader(compressed)

	for {
		header, err := reader.Next()
		if errors.Is(err, io.EOF) {
			return entries
		}

		if err != nil {
			t.Fatal(err)
		}

		content, _ := io.ReadAll(reader)
		entries[header.Name] = string(content)
	}
}

func TestServerBackupMethodsAreRegisteredAndOnlyRestoresLockTheAccount(t *testing.T) {
	registry := rpc.NewRegistry()
	Register(registry, Config{})

	for _, name := range []string{"backup.server_create", "backup.server_inspect", "backup.server_restore", "backup.server_delete", "backup.server_space"} {
		if !slices.Contains(registry.Names(), name) {
			t.Errorf("%s is not registered", name)
		}
	}

	methods := serverBackupMethods(newQuotaSystem(), &homeHelper{}, &fakeDatabaseServer{}, files.Transfers{}, BackupsConfig{})
	if methods["backup.server_create"].LockKey != nil || methods["backup.server_create"].Timeout != backupTimeout {
		t.Error("a backup must not hold the account's lock for hours")
	}

	if methods["backup.server_restore"].LockKey == nil || methods["backup.server_restore"].Timeout != backupTimeout {
		t.Error("a restore must lock the account and may run for hours")
	}
}

func TestAServerBackupIsKeptOutsideTheHomeWithTheMetaDumpsFilesAndMail(t *testing.T) {
	env := newServerBackupEnv(t)

	created, err := env.create(t, map[string]any{"files": true, "email": true, "databases": []string{"budi_shop"}})
	if err != nil || created.Size == 0 {
		t.Fatalf("create = %+v, %v", created, err)
	}

	path := filepath.Join(env.dir, created.Name)

	info, err := os.Stat(path)
	if err != nil || info.Mode().Perm() != 0o640 {
		t.Fatalf("backup = %v, %v", info, err)
	}

	entries := archiveEntries(t, path)

	for name, want := range map[string]string{
		files.BackupManifest:                            `{"format":1}`,
		files.BackupSettings:                            `{"mailboxes":[]}`,
		files.BackupFolder + "/databases/budi_shop.sql": "-- dump of budi_shop\n",
		"domains/budi.test/public_html/index.php":       "<?php echo 1;",
		"imap/budi.test/info/cur/1":                     "Subject: hi\n",
	} {
		if got, ok := entries[name]; !ok || got != want {
			t.Errorf("%s = %q (%v)", name, got, ok)
		}
	}

	for name := range entries {
		if strings.HasPrefix(name, "backups") || strings.HasPrefix(name, "tmp") {
			t.Errorf("%s is in the backup", name)
		}
	}

	// Nothing was written into the home, and no working file stayed.
	if _, err := os.Stat(filepath.Join(env.home, ".rayxo-restore")); !errors.Is(err, os.ErrNotExist) {
		t.Error("the home has a working folder")
	}

	if leftovers, _ := filepath.Glob(filepath.Join(env.dir, ".*")); len(leftovers) > 0 {
		t.Errorf("working files stayed: %v", leftovers)
	}

	raw, err := callBackup(env.methods, "backup.server_inspect", map[string]any{"name": created.Name})
	if err != nil || !strings.Contains(string(raw), `"manifest":"{\"format\":1}"`) {
		t.Errorf("inspect = %s, %v", raw, err)
	}

	if _, err := env.create(t, map[string]any{"files": true}); err == nil {
		t.Error("a backup was replaced")
	}
}

func TestAServerBackupOfOnlyTheMailLeavesTheFilesOut(t *testing.T) {
	env := newServerBackupEnv(t)

	created, err := env.create(t, map[string]any{"email": true})
	if err != nil {
		t.Fatal(err)
	}

	for name := range archiveEntries(t, filepath.Join(env.dir, created.Name)) {
		if !strings.HasPrefix(name, "imap") && !strings.HasPrefix(name, files.BackupFolder) {
			t.Errorf("%s is in a backup of the mail", name)
		}
	}
}

func TestAServerBackupIsRestoredIntoTheHomeAsTheAccount(t *testing.T) {
	env := newServerBackupEnv(t)

	created, err := env.create(t, map[string]any{"files": true, "email": true, "databases": []string{"budi_shop"}})
	if err != nil {
		t.Fatal(err)
	}

	index := filepath.Join(env.home, "domains/budi.test/public_html/index.php")
	mail := filepath.Join(env.home, "imap/budi.test/info/cur/1")
	writeHomeFile(t, index, "changed")
	writeHomeFile(t, filepath.Join(env.home, "domains/budi.test/public_html/new.php"), "kept")

	if err := os.Remove(mail); err != nil {
		t.Fatal(err)
	}

	// Only the databases first: the files stay as they are.
	if _, err := callBackup(env.methods, "backup.server_restore", map[string]any{"username": "budi", "name": created.Name, "databases": []string{"budi_shop"}}); err != nil {
		t.Fatal(err)
	}

	if env.databases.imported["budi_shop"] != "-- dump of budi_shop\n" || readHome(t, index) != "changed" {
		t.Errorf("imported = %v, index = %q", env.databases.imported, readHome(t, index))
	}

	raw, err := callBackup(env.methods, "backup.server_restore", map[string]any{"username": "budi", "name": created.Name, "files": true, "email": true})
	if err != nil {
		t.Fatal(err)
	}

	if readHome(t, index) != "<?php echo 1;" || readHome(t, mail) != "Subject: hi\n" || readHome(t, filepath.Join(env.home, "domains/budi.test/public_html/new.php")) != "kept" {
		t.Errorf("restored = %s", raw)
	}

	if _, err := os.Stat(filepath.Join(env.home, ".rayxo-restore")); !errors.Is(err, os.ErrNotExist) {
		t.Error("the restore folder stayed")
	}

	if !slices.Contains(env.helper.ops, files.OpBackupUnpack) {
		t.Errorf("ops = %v", env.helper.ops)
	}
}

func readHome(t *testing.T, path string) string {
	t.Helper()

	content, err := os.ReadFile(path)
	if err != nil {
		return "<" + err.Error() + ">"
	}

	return string(content)
}

func TestAServerBackupStopsBeforeTheDiskFillsAndLeavesNothing(t *testing.T) {
	env := newServerBackupEnv(t)
	env.free = 9 << 30

	_, err := env.create(t, map[string]any{"files": true})

	var refused *rpc.Error
	if !errors.As(err, &refused) || refused.Data.(map[string]string)["reason"] != reasonNoSpace {
		t.Fatalf("err = %v", err)
	}

	if names, _ := os.ReadDir(env.dir); len(names) > 0 {
		t.Errorf("left behind: %v", names)
	}

	raw, err := callBackup(env.methods, "backup.server_space", map[string]any{})
	if err != nil || !strings.Contains(string(raw), `"reserve":10737418240`) {
		t.Errorf("space = %s, %v", raw, err)
	}
}

func TestTheSpaceGuardStopsAWriteOnceTheDiskFills(t *testing.T) {
	checks := 0
	guard := &spaceGuard{out: io.Discard, space: func(string) (diskSpace, error) {
		checks++

		return diskSpace{Free: uint64(10-checks) << 30, Reserve: 8 << 30}, nil
	}}

	chunk := make([]byte, 1<<20)

	var err error

	for range 200 {
		if _, err = guard.Write(chunk); err != nil {
			break
		}
	}

	if guard.full == nil || !errors.Is(err, guard.full) || checks != 3 {
		t.Errorf("err = %v after %d checks", err, checks)
	}
}

func TestServerBackupsAreDeletedForGoodAndNeverThroughALink(t *testing.T) {
	env := newServerBackupEnv(t)

	created, err := env.create(t, map[string]any{"files": true})
	if err != nil {
		t.Fatal(err)
	}

	raw, err := callBackup(env.methods, "backup.server_delete", map[string]any{"names": []string{created.Name, "budi-gone.tar.gz"}})
	if err != nil || !strings.Contains(string(raw), created.Name) || !strings.Contains(string(raw), "budi-gone.tar.gz") {
		t.Fatalf("delete = %s, %v", raw, err)
	}

	if _, err := os.Stat(filepath.Join(env.dir, created.Name)); !errors.Is(err, os.ErrNotExist) {
		t.Error("the backup is still there")
	}

	secret := filepath.Join(env.home, "secret.tar.gz")
	writeHomeFile(t, secret, "not a backup")

	if err := os.Symlink(secret, filepath.Join(env.dir, "linked.tar.gz")); err != nil {
		t.Fatal(err)
	}

	if _, err := callBackup(env.methods, "backup.server_inspect", map[string]any{"name": "linked.tar.gz"}); err == nil {
		t.Error("read a backup through a link")
	}

	if _, err := callBackup(env.methods, "backup.server_delete", map[string]any{"names": []string{"linked.tar.gz"}}); err == nil {
		t.Error("deleted through a link")
	}

	if _, err := callBackup(env.methods, "backup.server_delete", map[string]any{"names": []string{"../budi.tar.gz"}}); err == nil {
		t.Error("accepted a name outside the folder")
	}

	if readHome(t, secret) != "not a backup" {
		t.Error("the file behind the link changed")
	}
}

func TestABackupFromAnotherServerIsMovedIntoPlaceOnlyWhenItIsOne(t *testing.T) {
	env := newServerBackupEnv(t)

	created, err := env.create(t, map[string]any{"files": true, "email": true})
	if err != nil {
		t.Fatal(err)
	}

	// The panel downloaded it from the other server as an upload.
	transfer := strings.Repeat("u", 32)
	if err := os.Rename(filepath.Join(env.dir, created.Name), env.transfers.UploadPath(transfer)); err != nil {
		t.Fatal(err)
	}

	raw, err := callBackup(env.methods, "backup.server_import", map[string]any{"transfer": transfer, "name": "budi-from-old.tar.gz"})
	if err != nil || !strings.Contains(string(raw), `"manifest":"{\"format\":1}"`) {
		t.Fatalf("import = %s, %v", raw, err)
	}

	info, err := os.Lstat(filepath.Join(env.dir, "budi-from-old.tar.gz"))
	if err != nil || info.Mode().Perm() != 0o640 {
		t.Fatalf("imported = %v, %v", info, err)
	}

	if _, err := os.Lstat(env.transfers.UploadPath(transfer)); !errors.Is(err, os.ErrNotExist) {
		t.Error("the upload stayed")
	}

	// Anything else is refused, and the upload goes.
	other := strings.Repeat("v", 32)
	writeHomeFile(t, env.transfers.UploadPath(other), "not a backup")

	if _, err := callBackup(env.methods, "backup.server_import", map[string]any{"transfer": other, "name": "evil.tar.gz"}); err == nil {
		t.Error("imported a file that is not a backup")
	}

	if _, err := os.Lstat(filepath.Join(env.dir, "evil.tar.gz")); !errors.Is(err, os.ErrNotExist) {
		t.Error("the refused file is in the backups folder")
	}

	if _, err := os.Lstat(env.transfers.UploadPath(other)); !errors.Is(err, os.ErrNotExist) {
		t.Error("the refused upload stayed")
	}

	// A name that is taken is never replaced.
	if err := os.Symlink("/etc/passwd", env.transfers.UploadPath(other)); err != nil {
		t.Fatal(err)
	}

	if _, err := callBackup(env.methods, "backup.server_import", map[string]any{"transfer": other, "name": "linked.tar.gz"}); err == nil {
		t.Error("imported through a link")
	}
}

func TestTheBackupsFolderIsListedWithoutLinksOrOtherFiles(t *testing.T) {
	env := newServerBackupEnv(t)

	if _, err := env.create(t, map[string]any{"files": true}); err != nil {
		t.Fatal(err)
	}

	writeHomeFile(t, filepath.Join(env.dir, "notes.txt"), "not a backup")

	if err := os.Symlink("/etc/passwd", filepath.Join(env.dir, "linked.tar.gz")); err != nil {
		t.Fatal(err)
	}

	raw, err := callBackup(env.methods, "backup.server_list", map[string]any{})
	if err != nil {
		t.Fatal(err)
	}

	var listed struct {
		Backups []serverBackupFile `json:"backups"`
	}
	_ = json.Unmarshal(raw, &listed)

	if len(listed.Backups) != 1 || listed.Backups[0].Name != "budi-2026-11-14-020000.tar.gz" || listed.Backups[0].Size == 0 {
		t.Errorf("listed = %+v", listed.Backups)
	}
}
