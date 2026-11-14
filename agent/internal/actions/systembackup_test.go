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

	"rayxo/agent/internal/files"
	"rayxo/agent/internal/rpc"
)

// panelDump fakes the dump of the panel's database.
type panelDump struct {
	name string
	fail error
}

func (d *panelDump) ExportPanel(_ context.Context, name string, out io.Writer) error {
	d.name = name
	if d.fail != nil {
		return d.fail
	}

	_, err := io.WriteString(out, "-- dump of the panel\n")

	return err
}

type systemBackupEnv struct {
	methods   map[string]rpc.Method
	dir       string
	sources   string
	transfers files.Transfers
	dump      *panelDump
	free      uint64
}

func newSystemBackupEnv(t *testing.T) *systemBackupEnv {
	t.Helper()

	env := &systemBackupEnv{dir: filepath.Join(t.TempDir(), "system"), sources: t.TempDir(), dump: &panelDump{}, free: 50 << 30}
	env.transfers = files.Transfers{Dir: t.TempDir(), PanelUID: os.Getuid()}

	for path, content := range map[string]string{
		"etc/panel.env":                  "APP_KEY=base64:secret\n",
		"etc/ssl/panel.key":              "private key",
		"dkim/example.com/rayxo.private": "dkim key",
		"storage/tickets/1/a.png":        "png",
	} {
		writeHomeFile(t, filepath.Join(env.sources, path), content)
	}

	// A link the panel user could plant: kept as a link, never read.
	if err := os.Symlink("/etc/passwd", filepath.Join(env.sources, "storage", "passwd")); err != nil {
		t.Fatal(err)
	}

	actions := systemBackupActions{
		config: BackupsConfig{
			SystemDir:     env.dir,
			PanelDatabase: "rayxo",
			SystemSources: SystemBackupSources{
				"etc-rayxo":     filepath.Join(env.sources, "etc"),
				"opendkim-keys": filepath.Join(env.sources, "dkim"),
				"storage":       filepath.Join(env.sources, "storage"),
				"missing":       filepath.Join(env.sources, "missing"),
			},
		},
		dumper:    env.dump,
		transfers: env.transfers,
		space: func(string) (diskSpace, error) {
			return diskSpace{Total: 100 << 30, Free: env.free, Reserve: 5 << 30}, nil
		},
	}

	env.methods = map[string]rpc.Method{
		"backup.system_create":   {Handler: actions.create},
		"backup.system_delete":   {Handler: actions.delete},
		"backup.system_download": {Handler: actions.download},
	}

	return env
}

func readTarGz(t *testing.T, path string) map[string]*tar.Header {
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

	entries := map[string]*tar.Header{}
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
		header.PAXRecords = map[string]string{"content": string(content)}
		entries[header.Name] = header
	}
}

func TestSystemBackupMethodsAreRegistered(t *testing.T) {
	registry := rpc.NewRegistry()
	Register(registry, Config{})

	for _, name := range []string{"backup.system_create", "backup.system_delete", "backup.system_download"} {
		if !slices.Contains(registry.Names(), name) {
			t.Errorf("%s is not registered", name)
		}
	}
}

func TestABackupOfThePanelHoldsItsDatabaseConfigurationKeysAndUploadsForRootAlone(t *testing.T) {
	env := newSystemBackupEnv(t)
	name := "rayxo-system-host-2026-11-14-020000.tar.gz"

	raw, err := callBackup(env.methods, "backup.system_create", map[string]any{"name": name, "manifest": map[string]any{"format": 1}})
	if err != nil || !strings.Contains(string(raw), name) {
		t.Fatalf("create = %s, %v", raw, err)
	}

	path := filepath.Join(env.dir, name)

	info, err := os.Stat(path)
	if err != nil || info.Mode().Perm() != 0o600 {
		t.Fatalf("backup = %v, %v", info, err)
	}

	if dir, _ := os.Stat(env.dir); dir.Mode().Perm() != 0o700 {
		t.Errorf("folder mode = %v", dir.Mode().Perm())
	}

	entries := readTarGz(t, path)

	for entry, want := range map[string]string{
		"rayxo-system/manifest.json":                           `{"format":1}`,
		"rayxo-system/panel.sql":                               "-- dump of the panel\n",
		"rayxo-system/etc-rayxo/panel.env":                     "APP_KEY=base64:secret\n",
		"rayxo-system/etc-rayxo/ssl/panel.key":                 "private key",
		"rayxo-system/opendkim-keys/example.com/rayxo.private": "dkim key",
		"rayxo-system/storage/tickets/1/a.png":                 "png",
	} {
		if header, ok := entries[entry]; !ok || header.PAXRecords["content"] != want {
			t.Errorf("%s = %v", entry, header)
		}
	}

	if link := entries["rayxo-system/storage/passwd"]; link == nil || link.Typeflag != tar.TypeSymlink || link.Linkname != "/etc/passwd" || link.Size != 0 {
		t.Errorf("link = %+v", link)
	}

	if env.dump.name != "rayxo" {
		t.Errorf("dumped %q", env.dump.name)
	}

	if leftovers, _ := filepath.Glob(filepath.Join(env.dir, ".*")); len(leftovers) > 0 {
		t.Errorf("working files stayed: %v", leftovers)
	}

	if _, err := callBackup(env.methods, "backup.system_create", map[string]any{"name": name, "manifest": map[string]any{}}); err == nil {
		t.Error("a backup was replaced")
	}
}

func TestABackupOfThePanelStopsBeforeTheDiskFillsAndWhenTheDumpFails(t *testing.T) {
	env := newSystemBackupEnv(t)
	env.free = 4 << 30

	_, err := callBackup(env.methods, "backup.system_create", map[string]any{"name": "full.tar.gz", "manifest": map[string]any{}})

	var refused *rpc.Error
	if !errors.As(err, &refused) || refused.Data.(map[string]string)["reason"] != reasonNoSpace {
		t.Fatalf("err = %v", err)
	}

	env.free = 50 << 30
	env.dump.fail = errors.New("mariadb-dump: access denied")

	if _, err := callBackup(env.methods, "backup.system_create", map[string]any{"name": "failed.tar.gz", "manifest": map[string]any{}}); err == nil {
		t.Error("a backup without the database was made")
	}

	if names, _ := os.ReadDir(env.dir); len(names) > 0 {
		t.Errorf("left behind: %v", names)
	}
}

func TestABackupOfThePanelIsDownloadedThroughATransferAndDeletedForGood(t *testing.T) {
	env := newSystemBackupEnv(t)
	name := "rayxo-system-a.tar.gz"
	transfer := strings.Repeat("d", 32)

	if _, err := callBackup(env.methods, "backup.system_create", map[string]any{"name": name, "manifest": map[string]any{}}); err != nil {
		t.Fatal(err)
	}

	if _, err := callBackup(env.methods, "backup.system_download", map[string]any{"name": name, "transfer": transfer}); err != nil {
		t.Fatal(err)
	}

	original, _ := os.ReadFile(filepath.Join(env.dir, name))
	copied, _ := os.ReadFile(env.transfers.DownloadPath(transfer))

	if len(original) == 0 || string(original) != string(copied) {
		t.Errorf("copied %d of %d bytes", len(copied), len(original))
	}

	if err := os.Symlink("/etc/shadow", filepath.Join(env.dir, "linked.tar.gz")); err != nil {
		t.Fatal(err)
	}

	if _, err := callBackup(env.methods, "backup.system_download", map[string]any{"name": "linked.tar.gz", "transfer": strings.Repeat("e", 32)}); err == nil {
		t.Error("downloaded through a link")
	}

	raw, err := callBackup(env.methods, "backup.system_delete", map[string]any{"names": []string{name, "gone.tar.gz"}})
	if err != nil {
		t.Fatal(err)
	}

	var deleted map[string][]string
	_ = json.Unmarshal(raw, &deleted)

	if !slices.Equal(deleted["deleted"], []string{name, "gone.tar.gz"}) {
		t.Errorf("deleted = %v", deleted)
	}

	if _, err := os.Stat(filepath.Join(env.dir, name)); !errors.Is(err, os.ErrNotExist) {
		t.Error("the backup is still there")
	}
}
