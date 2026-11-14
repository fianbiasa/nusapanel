package files

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"errors"
	"io"
	"os"
	"path/filepath"
	"slices"
	"strings"
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

// tarNames reads the names of the entries of an uncompressed tar stream,
// or of a tar.gz when compressed.
func tarNames(t *testing.T, data []byte, compressed bool) []string {
	t.Helper()

	var source io.Reader = bytes.NewReader(data)

	if compressed {
		reader, err := gzip.NewReader(source)
		if err != nil {
			t.Fatal(err)
		}

		source = reader
	}

	var names []string

	reader := tar.NewReader(source)

	for {
		header, err := reader.Next()
		if errors.Is(err, io.EOF) {
			return names
		}

		if err != nil {
			t.Fatal(err)
		}

		names = append(names, header.Name)
	}
}

func TestTheAgentsBackupHoldsTheMetaFirstThenTheStreamedHome(t *testing.T) {
	h, home, outside := newHome(t)

	writeFile(t, filepath.Join(home, "domains", "budi.test", "public_html", "index.php"), "<?php echo 1;", 0o644)
	writeFile(t, filepath.Join(home, "imap", "budi.test", "info", "cur", "1"), "Subject: hi\n", 0o600)
	writeFile(t, filepath.Join(outside, "secret"), "outside", 0o644)

	if err := os.Symlink("../../outside/secret", filepath.Join(home, "domains", "escape")); err != nil {
		t.Fatal(err)
	}

	var stream bytes.Buffer

	streamed, err := h.BackupStream(&stream, []string{"domains", "imap"})
	if err != nil || streamed.Entries != 10 || streamed.Skipped != 0 {
		t.Fatalf("stream = %+v, %v", streamed, err)
	}

	var archive bytes.Buffer

	out := NewBackupWriter(&archive)
	manifest := `{"format":1}`

	for _, err := range []error{
		out.AddFolder(BackupFolder),
		out.AddFile(BackupManifest, int64(len(manifest)), strings.NewReader(manifest)),
		out.AddFile(BackupSettings, 2, strings.NewReader("{}")),
		out.AddHome(&stream),
		out.Close(),
	} {
		if err != nil {
			t.Fatal(err)
		}
	}

	names := tarNames(t, archive.Bytes(), true)
	if !slices.Equal(names[:3], []string{BackupFolder + "/", BackupManifest, BackupSettings}) || !slices.Contains(names, "domains/budi.test/public_html/index.php") || !slices.Contains(names, "imap/budi.test/info/cur/1") {
		t.Errorf("names = %v", names)
	}

	meta, err := ReadBackupMeta(bytes.NewReader(archive.Bytes()))
	if err != nil || meta.Manifest != manifest || meta.Settings != "{}" {
		t.Errorf("meta = %+v, %v", meta, err)
	}

	// The link is kept as a link: restoring it into the home leads nowhere
	// the account could not already go.
	if got := readFile(t, filepath.Join(outside, "secret")); got != "outside" {
		t.Errorf("secret = %q", got)
	}

	// Only the files of the home come back into it, as a stream the files
	// helper unpacks; files made after the backup stay.
	writeFile(t, filepath.Join(home, "domains", "budi.test", "public_html", "index.php"), "changed", 0o644)
	writeFile(t, filepath.Join(home, "domains", "budi.test", "public_html", "new.php"), "kept", 0o644)

	var restore bytes.Buffer

	err = CopyBackupEntries(bytes.NewReader(archive.Bytes()), &restore, false, func(name string) (string, bool) {
		top, _, _ := strings.Cut(name, "/")

		return name, top == "domains"
	})
	if err != nil {
		t.Fatal(err)
	}

	result, err := h.BackupUnpack(&restore, "")
	if err != nil || result.Files != 1 || result.Links != 1 {
		t.Fatalf("unpack = %+v, %v", result, err)
	}

	if got := readFile(t, filepath.Join(home, "domains", "budi.test", "public_html", "index.php")); got != "<?php echo 1;" {
		t.Errorf("index.php = %q", got)
	}

	if got := readFile(t, filepath.Join(home, "domains", "budi.test", "public_html", "new.php")); got != "kept" {
		t.Errorf("new.php = %q", got)
	}
}

func TestTheAgentsBackupLeavesOutEntriesThatCouldLeadOutOrForgeItsMeta(t *testing.T) {
	var stream bytes.Buffer

	writer := tar.NewWriter(&stream)

	for _, name := range []string{"../escape.php", "/etc/passwd", BackupManifest, "site/./../../x", "site/index.php"} {
		if err := writer.WriteHeader(&tar.Header{Typeflag: tar.TypeReg, Name: name, Size: 2, Mode: 0o4755}); err != nil {
			t.Fatal(err)
		}

		if _, err := writer.Write([]byte("hi")); err != nil {
			t.Fatal(err)
		}
	}

	if err := writer.WriteHeader(&tar.Header{Typeflag: tar.TypeChar, Name: "site/tty"}); err != nil {
		t.Fatal(err)
	}

	if err := writer.Close(); err != nil {
		t.Fatal(err)
	}

	var archive bytes.Buffer

	out := NewBackupWriter(&archive)
	if err := errors.Join(out.AddHome(&stream), out.Close()); err != nil {
		t.Fatal(err)
	}

	if names := tarNames(t, archive.Bytes(), true); !slices.Equal(names, []string{"site/index.php"}) {
		t.Errorf("names = %v", names)
	}

	compressed, _ := gzip.NewReader(bytes.NewReader(archive.Bytes()))
	header, _ := tar.NewReader(compressed).Next()

	if header.Mode != 0o755 {
		t.Errorf("mode = %o, want the setuid bit dropped", header.Mode)
	}

	// The agent only names its own entries inside BackupFolder.
	for _, name := range []string{"site/x", "../" + BackupFolder, BackupFolder + "/../x"} {
		if out := NewBackupWriter(io.Discard); out.AddFile(name, 0, strings.NewReader("")) == nil {
			t.Errorf("added %s", name)
		}
	}
}

func TestOnlyTheMetaOfABackupIsReadWhenThatIsAllThatIsRestored(t *testing.T) {
	var archive bytes.Buffer

	out := NewBackupWriter(&archive)
	dump := "CREATE TABLE t (id int);\n"

	var stream bytes.Buffer

	writer := tar.NewWriter(&stream)
	_ = writer.WriteHeader(&tar.Header{Typeflag: tar.TypeDir, Name: "site/", Mode: 0o755})
	_ = writer.Close()

	if err := errors.Join(
		out.AddFolder(BackupFolder),
		out.AddFile(BackupManifest, 2, strings.NewReader("{}")),
		out.AddFolder(BackupFolder+"/databases"),
		out.AddFile(BackupFolder+"/databases/budi_shop.sql", int64(len(dump)), strings.NewReader(dump)),
		out.AddHome(&stream),
		out.Close(),
	); err != nil {
		t.Fatal(err)
	}

	var restore bytes.Buffer

	var seen []string

	err := CopyBackupEntries(bytes.NewReader(archive.Bytes()), &restore, true, func(name string) (string, bool) {
		seen = append(seen, name)

		return ".rayxo-restore/" + name, name == BackupFolder+"/databases/budi_shop.sql"
	})
	if err != nil {
		t.Fatal(err)
	}

	if slices.Contains(seen, "site") {
		t.Errorf("read past the meta: %v", seen)
	}

	if names := tarNames(t, restore.Bytes(), false); !slices.Equal(names, []string{".rayxo-restore/" + BackupFolder + "/databases/budi_shop.sql"}) {
		t.Errorf("names = %v", names)
	}
}

func TestABackupStreamSkipsWhatTheAccountCannotRead(t *testing.T) {
	if os.Geteuid() == 0 {
		t.Skip("root reads every file")
	}

	h, home, _ := newHome(t)

	writeFile(t, filepath.Join(home, "site", "index.php"), "hi", 0o644)
	writeFile(t, filepath.Join(home, "site", "locked.php"), "no", 0o000)

	streamed, err := h.BackupStream(io.Discard, []string{"site", "gone"})
	if err != nil || streamed.Skipped != 2 {
		t.Fatalf("stream = %+v, %v", streamed, err)
	}

	// The file manager's own archives still stop at what they cannot read.
	if _, err := h.Compress([]string{"site"}, "site.tar.gz", FormatTarGz); err == nil {
		t.Error("compressed a folder with an unreadable file")
	}
}
