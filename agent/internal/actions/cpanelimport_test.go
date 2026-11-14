package actions

import (
	"archive/tar"
	"compress/gzip"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"rayxo/agent/internal/files"
	"rayxo/agent/internal/rpc"
)

// cpanelEntry is one entry of a cPanel backup a test writes.
type cpanelEntry struct {
	name    string
	content string
	link    string
	dir     bool
}

// writeCpanelBackup writes a cPanel backup of account budi, in the order
// pkgacct writes one: what cPanel knows of the account, the dumps, then
// the home.
func writeCpanelBackup(t *testing.T, path string, entries []cpanelEntry) {
	t.Helper()

	if err := os.MkdirAll(filepath.Dir(path), 0o750); err != nil {
		t.Fatal(err)
	}

	file, err := os.Create(path)
	if err != nil {
		t.Fatal(err)
	}
	defer file.Close()

	compressed := gzip.NewWriter(file)
	writer := tar.NewWriter(compressed)

	for _, entry := range entries {
		header := &tar.Header{Name: entry.name, Mode: 0o644, Typeflag: tar.TypeReg, Size: int64(len(entry.content))}

		switch {
		case entry.dir:
			header = &tar.Header{Name: entry.name + "/", Mode: 0o750, Typeflag: tar.TypeDir}
		case entry.link != "":
			header = &tar.Header{Name: entry.name, Mode: 0o777, Typeflag: tar.TypeSymlink, Linkname: entry.link}
		}

		if err := writer.WriteHeader(header); err != nil {
			t.Fatal(err)
		}

		if header.Typeflag == tar.TypeReg {
			if _, err := writer.Write([]byte(entry.content)); err != nil {
				t.Fatal(err)
			}
		}
	}

	if err := writer.Close(); err != nil {
		t.Fatal(err)
	}

	if err := compressed.Close(); err != nil {
		t.Fatal(err)
	}
}

const cpanelTrigger = "/*!50003 CREATE*/ /*!50017 DEFINER=`budi`@`localhost`*/ /*!50003 TRIGGER stamp BEFORE INSERT ON items FOR EACH ROW SET NEW.id = NEW.id */;;\n"

func cpanelBackupEntries() []cpanelEntry {
	return []cpanelEntry{
		{name: "cpmove-budi", dir: true},
		{name: "cpmove-budi/cp/budi", content: "USER=budi\nDNS=budi.test\nCONTACTEMAIL=budi@example.net\n"},
		{name: "cpmove-budi/userdata/main", content: "---\nmain_domain: budi.test\n"},
		{name: "cpmove-budi/va/budi.test", content: "sales@budi.test: boss@example.net\n"},
		{name: "cpmove-budi/mysql.sql", content: "GRANT USAGE ON *.* TO 'budi_wp'@'localhost' IDENTIFIED BY PASSWORD '*24ECEAB08897F9D6E6E7B1FDAE8A81780F44CBCA';\n"},
		{name: "cpmove-budi/dnszones/budi.test.db", content: "; zone"},
		{name: "cpmove-budi/mysql/budi_shop.sql", content: "CREATE TABLE items (id int);\nINSERT INTO items VALUES (1),('DEFINER=`x`@`y`');\n" + cpanelTrigger},
		{name: "cpmove-budi/mysql/budi_shop.create", content: "CREATE DATABASE budi_shop;"},
		{name: "cpmove-budi/homedir", dir: true},
		{name: "cpmove-budi/homedir/public_html", dir: true},
		{name: "cpmove-budi/homedir/public_html/index.php", content: "<?php echo 'main';"},
		{name: "cpmove-budi/homedir/public_html/addon.test/index.html", content: "addon"},
		{name: "cpmove-budi/homedir/etc/budi.test/shadow", content: "info:$6$salt$hash:20771::::::\n"},
		{name: "cpmove-budi/homedir/mail/budi.test/info/cur/1", content: "Subject: hi\n"},
		{name: "cpmove-budi/homedir/mail/budi.test/info/.Sent/cur/2", content: "Subject: sent\n"},
		{name: "cpmove-budi/homedir/mail/budi.test/info/.Sent/dovecot.index.cache", content: "cache"},
		{name: "cpmove-budi/homedir/mail/budi.test/info/dovecot-uidlist", content: "3 V1 N3\n"},
		{name: "cpmove-budi/homedir/mail/budi.test/info/maildirsize", content: "524288000S\n"},
		{name: "cpmove-budi/homedir/mail/new/3", content: "Subject: to the cPanel account\n"},
		{name: "cpmove-budi/homedir/.cpanel/datastore", content: "cPanel's own"},
		{name: "cpmove-budi/homedir/perl5/Lib.pm", content: "1;"},
		{name: "cpmove-budi/homedir/www", link: "public_html"},
	}
}

func cpanelRules() []files.CpanelRule {
	return []files.CpanelRule{
		{From: "public_html", To: "domains/budi.test/public_html"},
		{From: "public_html/addon.test", To: "domains/addon.test/public_html"},
		{From: "mail/budi.test/info", To: "imap/budi.test/info/Maildir", Mail: true},
		{From: "mail", To: ""},
		{From: "perl5", To: "perl5"},
	}
}

func (env *serverBackupEnv) convertCpanel(t *testing.T, params map[string]any) (map[string]any, error) {
	t.Helper()

	base := map[string]any{
		"source":    "cpmove-budi.tar.gz",
		"name":      "budi-cpanel.tar.gz",
		"top":       "cpmove-budi",
		"manifest":  map[string]any{"format": 1, "username": "budi"},
		"settings":  map[string]any{"mailboxes": []any{}},
		"databases": []map[string]string{{"from": "budi_shop", "to": "budi_shop"}},
		"rules":     cpanelRules(),
	}
	for key, value := range params {
		base[key] = value
	}

	raw, err := callBackup(env.methods, "backup.cpanel_convert", base)
	if err != nil {
		return nil, err
	}

	var result map[string]any

	return result, json.Unmarshal(raw, &result)
}

func TestCpanelImportMethodsAreRegisteredForHours(t *testing.T) {
	methods := serverBackupMethods(newQuotaSystem(), &homeHelper{}, &fakeDatabaseServer{}, files.Transfers{}, BackupsConfig{})

	for _, name := range []string{"backup.cpanel_inspect", "backup.cpanel_convert"} {
		if methods[name].Handler == nil || methods[name].Timeout != backupTimeout || methods[name].LockKey != nil {
			t.Errorf("%s must be registered, run for hours and lock no account", name)
		}
	}
}

func TestACpanelBackupIsReadThrough(t *testing.T) {
	env := newServerBackupEnv(t)
	writeCpanelBackup(t, filepath.Join(env.dir, "cpmove-budi.tar.gz"), cpanelBackupEntries())

	raw, err := callBackup(env.methods, "backup.cpanel_inspect", map[string]any{"name": "cpmove-budi.tar.gz"})
	if err != nil {
		t.Fatal(err)
	}

	var inspection files.CpanelInspection
	if err := json.Unmarshal(raw, &inspection); err != nil {
		t.Fatal(err)
	}

	if inspection.Top != "cpmove-budi" || inspection.Files["cp/budi"] == "" || inspection.Files["userdata/main"] == "" ||
		inspection.Files["va/budi.test"] == "" || inspection.Files["mysql.sql"] == "" || inspection.Files["homedir/etc/budi.test/shadow"] == "" {
		t.Errorf("inspection = %+v", inspection)
	}

	if _, read := inspection.Files["dnszones/budi.test.db"]; read {
		t.Error("only the files the panel needs are read")
	}

	if len(inspection.Databases) != 1 || inspection.Databases[0].Name != "budi_shop" || inspection.Databases[0].Size == 0 {
		t.Errorf("databases = %+v", inspection.Databases)
	}

	names := []string{}
	for _, entry := range inspection.Home {
		names = append(names, entry.Name+":"+entry.Type)
	}

	if !slices.Equal(names, []string{".cpanel:directory", "etc:directory", "mail:directory", "perl5:directory", "public_html:directory", "www:link"}) {
		t.Errorf("home = %v", names)
	}

	if inspection.Entries != 13 || inspection.Bytes == 0 {
		t.Errorf("entries = %d, bytes = %d", inspection.Entries, inspection.Bytes)
	}
}

func TestOnlyACpanelBackupIsReadAsOne(t *testing.T) {
	env := newServerBackupEnv(t)

	created, err := env.create(t, map[string]any{"files": true})
	if err != nil {
		t.Fatal(err)
	}

	writeCpanelBackup(t, filepath.Join(env.dir, "no-account.tar.gz"), []cpanelEntry{{name: "top/homedir/a", content: "a"}})

	for _, name := range []string{created.Name, "no-account.tar.gz"} {
		_, err := callBackup(env.methods, "backup.cpanel_inspect", map[string]any{"name": name})

		var refused *rpc.Error
		if !errors.As(err, &refused) || !strings.Contains(refused.Message, "not a cPanel backup") {
			t.Errorf("%s: err = %v", name, err)
		}
	}
}

func TestACpanelBackupBecomesARayxoBackupThatRestores(t *testing.T) {
	env := newServerBackupEnv(t)
	source := filepath.Join(env.dir, "cpmove-budi.tar.gz")
	writeCpanelBackup(t, source, cpanelBackupEntries())

	result, err := env.convertCpanel(t, nil)
	if err != nil {
		t.Fatal(err)
	}

	if result["name"] != "budi-cpanel.tar.gz" || result["size"].(float64) == 0 || !strings.Contains(result["manifest"].(string), `"username":"budi"`) {
		t.Errorf("result = %v", result)
	}

	entries := archiveEntries(t, filepath.Join(env.dir, "budi-cpanel.tar.gz"))
	names := slices.Sorted(func(yield func(string) bool) {
		for name := range entries {
			if !yield(name) {
				return
			}
		}
	})

	want := []string{
		".rayxo-backup/", ".rayxo-backup/databases/", ".rayxo-backup/databases/budi_shop.sql", ".rayxo-backup/manifest.json", ".rayxo-backup/settings.json",
		"domains/addon.test/public_html/index.html", "domains/budi.test/public_html/index.php",
		"imap/budi.test/info/Maildir/.Sent/cur/2", "imap/budi.test/info/Maildir/cur/1", "imap/budi.test/info/Maildir/dovecot-uidlist",
		"perl5/Lib.pm",
	}
	if !slices.Equal(names, want) {
		t.Errorf("entries = %v", names)
	}

	dump := entries[".rayxo-backup/databases/budi_shop.sql"]
	if !strings.Contains(dump, "/*!50017 DEFINER=CURRENT_USER*/") || !strings.Contains(dump, "('DEFINER=`x`@`y`')") {
		t.Errorf("dump = %q", dump)
	}

	if _, err := os.Stat(source); err != nil {
		t.Error("the cPanel backup must stay")
	}

	if leftovers, _ := filepath.Glob(filepath.Join(env.dir, ".*")); len(leftovers) != 0 {
		t.Errorf("left behind: %v", leftovers)
	}

	if _, err := callBackup(env.methods, "backup.server_inspect", map[string]any{"name": "budi-cpanel.tar.gz"}); err != nil {
		t.Fatalf("the result must read as a RAYXO backup: %v", err)
	}

	if _, err := callBackup(env.methods, "backup.server_restore", map[string]any{"username": "budi", "name": "budi-cpanel.tar.gz", "files": true, "email": true, "databases": []string{"budi_shop"}}); err != nil {
		t.Fatal(err)
	}

	if readHome(t, filepath.Join(env.home, "domains/addon.test/public_html/index.html")) != "addon" ||
		readHome(t, filepath.Join(env.home, "imap/budi.test/info/Maildir/cur/1")) != "Subject: hi\n" ||
		!strings.Contains(env.databases.imported["budi_shop"], "DEFINER=CURRENT_USER") {
		t.Error("the converted backup did not restore")
	}
}

func TestACpanelConversionThatFailsLeavesNothing(t *testing.T) {
	env := newServerBackupEnv(t)
	writeCpanelBackup(t, filepath.Join(env.dir, "cpmove-budi.tar.gz"), cpanelBackupEntries())

	_, err := env.convertCpanel(t, map[string]any{"databases": []map[string]string{{"from": "budi_gone", "to": "budi_gone"}}})
	if err == nil || !strings.Contains(err.Error(), "no dump of budi_gone") {
		t.Errorf("err = %v", err)
	}

	env.free = 9 << 30

	_, err = env.convertCpanel(t, nil)

	var refused *rpc.Error
	if !errors.As(err, &refused) || refused.Data.(map[string]string)["reason"] != reasonNoSpace {
		t.Errorf("err = %v", err)
	}

	if leftovers, _ := filepath.Glob(filepath.Join(env.dir, "*")); !slices.Equal(leftovers, []string{filepath.Join(env.dir, "cpmove-budi.tar.gz")}) {
		t.Errorf("left behind: %v", leftovers)
	}
}

func TestACpanelConversionOnlyWritesInsideTheHomeAndTheBackupsFolder(t *testing.T) {
	env := newServerBackupEnv(t)
	writeCpanelBackup(t, filepath.Join(env.dir, "cpmove-budi.tar.gz"), cpanelBackupEntries())

	for name, params := range map[string]map[string]any{
		"rule out of the home":        {"rules": []files.CpanelRule{{From: "public_html", To: "../etc"}}},
		"rule into the backup's meta": {"rules": []files.CpanelRule{{From: "public_html", To: ".rayxo-backup/settings.json"}}},
		"rule from out of the home":   {"rules": []files.CpanelRule{{From: "../x", To: "x"}}},
		"replacing the source":        {"name": "cpmove-budi.tar.gz"},
		"source by a path":            {"source": "../cpmove-budi.tar.gz"},
		"database name here":          {"databases": []map[string]string{{"from": "budi_shop", "to": "Budi-Shop"}}},
		"database named twice":        {"databases": []map[string]string{{"from": "a", "to": "budi_a"}, {"from": "b", "to": "budi_a"}}},
		"top by a path":               {"top": "../cpmove-budi"},
	} {
		_, err := env.convertCpanel(t, params)

		var refused *rpc.Error
		if !errors.As(err, &refused) || refused.Code != rpc.CodeInvalidParams {
			t.Errorf("%s: err = %v", name, err)
		}
	}
}
