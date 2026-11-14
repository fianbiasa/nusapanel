package files

import (
	"os"
	"path/filepath"
	"testing"
)

func TestABackupIsPackedReadAndRestoredByFolder(t *testing.T) {
	h, home, outside := newHome(t)

	writeFile(t, filepath.Join(home, BackupManifest), `{"format":1}`, 0o600)
	writeFile(t, filepath.Join(home, BackupSettings), `{"databases":[]}`, 0o600)
	writeFile(t, filepath.Join(home, BackupFolder, "databases", "budi_shop.sql"), "CREATE TABLE t (id int);\n", 0o600)
	writeFile(t, filepath.Join(home, "domains", "budi.test", "public_html", "index.php"), "<?php echo 1;", 0o644)
	writeFile(t, filepath.Join(home, "imap", "budi.test", "info", "Maildir", "cur", "1"), "Subject: hi\n", 0o600)
	writeFile(t, filepath.Join(outside, "secret"), "outside", 0o644)

	if err := os.Symlink("public_html", filepath.Join(home, "domains", "budi.test", "private_html")); err != nil {
		t.Fatal(err)
	}

	if err := os.Mkdir(filepath.Join(home, "backups"), 0o700); err != nil {
		t.Fatal(err)
	}

	entry, err := h.BackupPack([]string{BackupFolder, "domains", "imap"}, "backups/budi.tar.gz")
	if err != nil || entry.Size == 0 {
		t.Fatalf("pack = %+v, %v", entry, err)
	}

	meta, err := h.BackupMeta("backups/budi.tar.gz")
	if err != nil || meta.Manifest != `{"format":1}` || meta.Settings != `{"databases":[]}` {
		t.Fatalf("meta = %+v, %v", meta, err)
	}

	// The site changes after the backup; restoring the files puts it back,
	// leaves the mail alone and keeps the metadata out of the home.
	writeFile(t, filepath.Join(home, "domains", "budi.test", "public_html", "index.php"), "changed", 0o644)
	writeFile(t, filepath.Join(home, "domains", "budi.test", "public_html", "new.php"), "kept", 0o644)
	writeFile(t, filepath.Join(home, "imap", "budi.test", "info", "Maildir", "cur", "1"), "newer mail", 0o600)

	if err := os.RemoveAll(filepath.Join(home, BackupFolder)); err != nil {
		t.Fatal(err)
	}

	result, err := h.BackupExtract("backups/budi.tar.gz", "", nil, []string{BackupFolder, "imap", "backups"})
	if err != nil || result.Files != 1 || result.Links != 1 {
		t.Fatalf("extract = %+v, %v", result, err)
	}

	if got := readFile(t, filepath.Join(home, "domains", "budi.test", "public_html", "index.php")); got != "<?php echo 1;" {
		t.Errorf("index.php = %q", got)
	}

	if got := readFile(t, filepath.Join(home, "domains", "budi.test", "public_html", "new.php")); got != "kept" {
		t.Errorf("new.php = %q", got)
	}

	if got := readFile(t, filepath.Join(home, "imap", "budi.test", "info", "Maildir", "cur", "1")); got != "newer mail" {
		t.Errorf("mail = %q", got)
	}

	if _, err := os.Stat(filepath.Join(home, BackupFolder)); !os.IsNotExist(err) {
		t.Errorf("the metadata was restored into the home: %v", err)
	}

	// The dumps go to a folder of their own.
	if _, err := h.BackupExtract("backups/budi.tar.gz", ".rayxo-restore", []string{BackupFolder}, nil); err != nil {
		t.Fatal(err)
	}

	if got := readFile(t, filepath.Join(home, ".rayxo-restore", BackupFolder, "databases", "budi_shop.sql")); got != "CREATE TABLE t (id int);\n" {
		t.Errorf("dump = %q", got)
	}
}

func TestBackupsRefuseWhatIsNotOne(t *testing.T) {
	h, home, _ := newHome(t)

	writeFile(t, filepath.Join(home, "site", "index.html"), "hi", 0o644)

	if _, err := h.BackupPack([]string{"site"}, "site.zip"); err == nil {
		t.Error("packed a backup that is not a .tar.gz")
	}

	if _, err := h.Compress([]string{"site"}, "plain.tar.gz", FormatTarGz); err != nil {
		t.Fatal(err)
	}

	_, err := h.BackupMeta("plain.tar.gz")
	wantReason(t, err, ReasonInvalid)

	_, err = h.BackupMeta("../outside.tar.gz")
	if err == nil {
		t.Error("read a backup outside the home")
	}

	if (BackupExtractParams{Archive: "plain.tar.gz", Include: []string{"../etc"}}).Check() == nil {
		t.Error("accepted a folder outside the archive's top")
	}
}
