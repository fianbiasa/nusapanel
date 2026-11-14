package actions

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"os"
	"slices"
	"strings"
	"testing"

	"rayxo/agent/internal/files"
	"rayxo/agent/internal/rpc"
)

// backupHelper fakes the files helper: it keeps what was uploaded, lists a
// home with the given top folders, and records every operation.
type backupHelper struct {
	top      []string
	uploaded map[string]string
	ops      []string
	fail     map[string]error
}

func (h *backupHelper) Run(_ context.Context, _ files.Account, op string, params any, data *os.File, result any) error {
	encoded, _ := json.Marshal(params)
	h.ops = append(h.ops, op+" "+string(encoded))

	if op == files.OpUpload {
		content, _ := io.ReadAll(data)
		h.uploaded[params.(files.UploadParams).Path] = string(content)
	}

	if err := h.fail[op]; err != nil {
		return err
	}

	if result == nil {
		return nil
	}

	switch op {
	case files.OpList:
		listing := files.Listing{}
		for _, name := range h.top {
			listing.Entries = append(listing.Entries, files.Entry{Name: name})
		}

		encoded, _ = json.Marshal(listing)
	case files.OpBackupExtract:
		encoded = []byte(`{"destination":"","files":3}`)
	default:
		encoded = []byte(`{"name":"done"}`)
	}

	return json.Unmarshal(encoded, result)
}

func backupEnv() (map[string]rpc.Method, *backupHelper, *fakeDatabaseServer) {
	helper := &backupHelper{top: []string{"domains", "imap", "backups", ".trash", "tmp", "notes.txt"}, uploaded: map[string]string{}, fail: map[string]error{}}
	databases := &fakeDatabaseServer{}

	return backupMethods(newQuotaSystem(), helper, databases), helper, databases
}

func callBackup(methods map[string]rpc.Method, method string, params any) (json.RawMessage, error) {
	raw, _ := json.Marshal(params)

	result, err := methods[method].Handler(context.Background(), raw)
	if err != nil {
		return nil, err
	}

	encoded, _ := json.Marshal(result)

	return encoded, nil
}

func TestBackupMethodsAreRegisteredAndLockTheAccountForHours(t *testing.T) {
	registry := rpc.NewRegistry()
	Register(registry, Config{})

	for _, name := range []string{"backup.create", "backup.inspect", "backup.restore", "backup.delete"} {
		if !slices.Contains(registry.Names(), name) {
			t.Errorf("%s is not registered", name)
		}
	}

	methods, _, _ := backupEnv()
	if methods["backup.create"].Timeout < 6*60*60*1e9 || methods["backup.create"].LockKey == nil || methods["backup.restore"].LockKey == nil {
		t.Error("backups and restores must lock the account and may run for hours")
	}
}

func TestABackupHoldsTheMetadataDumpsFilesAndMail(t *testing.T) {
	methods, helper, databases := backupEnv()

	_, err := callBackup(methods, "backup.create", map[string]any{
		"username": "budi", "name": "budi-2026-11-14.tar.gz", "files": true, "email": true,
		"databases": []string{"budi_shop"}, "manifest": map[string]any{"format": 1}, "settings": map[string]any{"mailboxes": []any{}},
	})
	if err != nil {
		t.Fatal(err)
	}

	if helper.uploaded[files.BackupManifest] != `{"format":1}` || helper.uploaded[files.BackupSettings] != `{"mailboxes":[]}` {
		t.Errorf("metadata = %v", helper.uploaded)
	}

	if helper.uploaded[files.BackupFolder+"/databases/budi_shop.sql"] != "-- dump of budi_shop\n" || !slices.Contains(databases.calls, "export budi budi_shop") {
		t.Errorf("dump = %v, calls = %v", helper.uploaded, databases.calls)
	}

	pack := `backup_pack {"paths":[".rayxo-backup","domains","imap","notes.txt"],"destination":"backups/budi-2026-11-14.tar.gz"}`
	if !slices.Contains(helper.ops, pack) {
		t.Errorf("ops = %v", helper.ops)
	}

	if last := helper.ops[len(helper.ops)-1]; last != `remove {"paths":[".rayxo-backup"]}` {
		t.Errorf("the working folder stayed: %s", last)
	}
}

func TestABackupOfOnlyTheMailLeavesTheFilesOut(t *testing.T) {
	methods, helper, _ := backupEnv()

	if _, err := callBackup(methods, "backup.create", map[string]any{"username": "budi", "name": "mail.tar.gz", "email": true, "manifest": map[string]any{}, "settings": map[string]any{}}); err != nil {
		t.Fatal(err)
	}

	if !slices.Contains(helper.ops, `backup_pack {"paths":[".rayxo-backup","imap"],"destination":"backups/mail.tar.gz"}`) {
		t.Errorf("ops = %v", helper.ops)
	}
}

func TestAFailedDumpFailsTheBackupAndCleansUp(t *testing.T) {
	methods, helper, databases := backupEnv()
	databases.fail = errors.New("mariadb-dump: no such database")

	_, err := callBackup(methods, "backup.create", map[string]any{"username": "budi", "name": "x.tar.gz", "files": true, "databases": []string{"budi_gone"}, "manifest": map[string]any{}, "settings": map[string]any{}})
	if err == nil {
		t.Fatal("the backup succeeded")
	}

	if slices.ContainsFunc(helper.ops, func(op string) bool { return strings.HasPrefix(op, files.OpBackupPack) }) {
		t.Error("packed a backup without its database")
	}

	if last := helper.ops[len(helper.ops)-1]; last != `remove {"paths":[".rayxo-backup"]}` {
		t.Errorf("the working folder stayed: %s", last)
	}
}

func TestARestorePutsBackWhatWasAsked(t *testing.T) {
	methods, helper, databases := backupEnv()

	result, err := callBackup(methods, "backup.restore", map[string]any{"username": "budi", "name": "b.tar.gz", "files": true, "databases": []string{"budi_shop"}})
	if err != nil {
		t.Fatal(err)
	}

	want := []string{
		`backup_extract {"archive":"backups/b.tar.gz","destination":"","include":null,"exclude":[".rayxo-backup","backups",".rayxo-restore","imap"]}`,
		`remove {"paths":[".rayxo-restore"]}`,
		`backup_extract {"archive":"backups/b.tar.gz","destination":".rayxo-restore","include":[".rayxo-backup"],"exclude":null}`,
		`remove {"paths":[".rayxo-restore"]}`,
	}
	if !slices.Equal(helper.ops, want) {
		t.Errorf("ops = %q", helper.ops)
	}

	if !slices.Contains(databases.calls, "import budi budi_shop /home/budi/.rayxo-restore/.rayxo-backup/databases/budi_shop.sql as 1001") {
		t.Errorf("calls = %v", databases.calls)
	}

	if string(result) != `{"files":{"destination":"","files":3,"folders":0,"links":0,"skipped":0,"refused":0},"databases":["budi_shop"]}` {
		t.Errorf("result = %s", result)
	}
}

func TestARestoreOfOnlyTheMailTakesOnlyItsFolder(t *testing.T) {
	methods, helper, _ := backupEnv()

	if _, err := callBackup(methods, "backup.restore", map[string]any{"username": "budi", "name": "b.tar.gz", "email": true}); err != nil {
		t.Fatal(err)
	}

	if !slices.Equal(helper.ops, []string{`backup_extract {"archive":"backups/b.tar.gz","destination":"","include":["imap"],"exclude":[".rayxo-backup","backups",".rayxo-restore"]}`}) {
		t.Errorf("ops = %q", helper.ops)
	}
}

func TestBackupParamsAreChecked(t *testing.T) {
	methods, _, _ := backupEnv()

	for _, params := range []map[string]any{
		{"username": "budi", "name": "../x.tar.gz", "manifest": map[string]any{}, "settings": map[string]any{}},
		{"username": "budi", "name": "x.zip", "manifest": map[string]any{}, "settings": map[string]any{}},
		{"username": "budi", "name": "x.tar.gz", "databases": []string{"sari_shop"}, "manifest": map[string]any{}, "settings": map[string]any{}},
		{"username": "budi", "name": "x.tar.gz", "databases": []string{"budi_a", "budi_a"}, "manifest": map[string]any{}, "settings": map[string]any{}},
		{"username": "budi", "name": "x.tar.gz", "manifest": []any{}, "settings": map[string]any{}},
		{"username": "root", "name": "x.tar.gz", "manifest": map[string]any{}, "settings": map[string]any{}},
	} {
		if _, err := callBackup(methods, "backup.create", params); err == nil {
			t.Errorf("accepted %v", params)
		}
	}
}

func TestABackupIsDeletedForGood(t *testing.T) {
	methods, helper, _ := backupEnv()

	if _, err := callBackup(methods, "backup.delete", map[string]any{"username": "budi", "name": "old.tar.gz"}); err != nil {
		t.Fatal(err)
	}

	if !slices.Equal(helper.ops, []string{`remove {"paths":["backups/old.tar.gz"]}`}) {
		t.Errorf("ops = %q", helper.ops)
	}

	if _, err := callBackup(methods, "backup.delete", map[string]any{"username": "budi", "name": "../notes.txt"}); err == nil {
		t.Error("deleted something that is not a backup")
	}
}
