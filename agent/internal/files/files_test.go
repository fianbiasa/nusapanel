package files

import (
	"bytes"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"
)

// newHome returns a home folder in a temporary directory, next to a folder
// "outside" that no operation may reach.
func newHome(t *testing.T) (*Home, string, string) {
	t.Helper()

	base := t.TempDir()
	home := filepath.Join(base, "home")
	outside := filepath.Join(base, "outside")

	for _, dir := range []string{home, outside} {
		if err := os.Mkdir(dir, 0o755); err != nil {
			t.Fatal(err)
		}
	}

	h, err := OpenHome(home)
	if err != nil {
		t.Fatal(err)
	}

	t.Cleanup(func() { _ = h.Close() })

	return h, home, outside
}

func writeFile(t *testing.T, path, content string, mode os.FileMode) {
	t.Helper()

	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		t.Fatal(err)
	}

	if err := os.WriteFile(path, []byte(content), mode); err != nil {
		t.Fatal(err)
	}

	if err := os.Chmod(path, mode); err != nil {
		t.Fatal(err)
	}
}

func readFile(t *testing.T, path string) string {
	t.Helper()

	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}

	return string(data)
}

func wantReason(t *testing.T, err error, reason string) {
	t.Helper()

	var failure *Error
	if !errors.As(err, &failure) {
		t.Fatalf("error = %v, want reason %s", err, reason)
	}

	if failure.Reason != reason {
		t.Fatalf("reason = %s (%s), want %s", failure.Reason, failure.Message, reason)
	}

	if strings.Contains(failure.Message, os.TempDir()) || strings.Contains(failure.Message, "/private/") {
		t.Fatalf("message %q shows a path outside the home", failure.Message)
	}
}

func TestCheckPathRefusesAnythingThatCouldLeaveTheHome(t *testing.T) {
	for _, path := range []string{"/etc", "../x", "a/../b", "a/./b", "a//b", "a/", "a\x00b", "a/\nb", strings.Repeat("x", 256)} {
		if err := CheckPath(path); err == nil {
			t.Errorf("CheckPath(%q) accepted", path)
		}
	}

	for _, path := range []string{"", "domains/example.com/public_html", ".htaccess", "a b/é.txt", "..hidden"} {
		if err := CheckPath(path); err != nil {
			t.Errorf("CheckPath(%q) = %v", path, err)
		}
	}

	if err := CheckName("a/b"); err == nil {
		t.Error("CheckName accepted a slash")
	}
}

func TestListShowsFoldersFirstAndWhereLinksPoint(t *testing.T) {
	h, home, outside := newHome(t)

	writeFile(t, filepath.Join(home, "b.txt"), "hello", 0o640)
	writeFile(t, filepath.Join(home, "A.php"), "<?php", 0o644)
	writeFile(t, filepath.Join(outside, "secret"), "x", 0o644)

	if err := os.Mkdir(filepath.Join(home, "zfolder"), 0o750); err != nil {
		t.Fatal(err)
	}

	for name, target := range map[string]string{
		"link-to-folder": "zfolder",
		"broken":         "missing",
		"escape":         filepath.Join(outside, "secret"),
	} {
		if err := os.Symlink(target, filepath.Join(home, name)); err != nil {
			t.Fatal(err)
		}
	}

	listing, err := h.List("")
	if err != nil {
		t.Fatal(err)
	}

	var names []string
	types := map[string]string{}

	for _, entry := range listing.Entries {
		names = append(names, entry.Name)
		types[entry.Name] = entry.Type + "/" + entry.TargetType
	}

	want := []string{"link-to-folder", "zfolder", "A.php", "b.txt", "broken", "escape"}
	if !slices.Equal(names, want) {
		t.Fatalf("names = %v, want %v", names, want)
	}

	if types["escape"] != "link/outside" || types["broken"] != "link/missing" || types["link-to-folder"] != "link/directory" {
		t.Fatalf("types = %v", types)
	}

	if entry := listing.Entries[3]; entry.Size != 5 || entry.Mode != "0640" {
		t.Fatalf("b.txt = %+v", entry)
	}

	_, err = h.List("b.txt")
	wantReason(t, err, ReasonNotDirectory)
}

func TestNothingLeavesTheHomeThroughALink(t *testing.T) {
	h, home, outside := newHome(t)

	writeFile(t, filepath.Join(outside, "secret"), "x", 0o644)

	if err := os.Symlink(outside, filepath.Join(home, "out")); err != nil {
		t.Fatal(err)
	}

	_, err := h.List("out")
	wantReason(t, err, ReasonOutside)

	_, err = h.Read("out/secret")
	wantReason(t, err, ReasonOutside)

	wantReason(t, h.Mkdir("out/new"), ReasonOutside)
	wantReason(t, h.CreateFile("out/new.txt"), ReasonOutside)

	_, err = h.Upload(strings.NewReader("data"), "out/up.txt", false)
	wantReason(t, err, ReasonOutside)

	var buf bytes.Buffer
	_, err = h.Download(&buf, "out/secret")
	wantReason(t, err, ReasonOutside)

	outcome := h.Move([]string{"out"}, "out")
	if len(outcome.Failed) != 1 {
		t.Fatalf("move into the link = %+v", outcome)
	}

	if entries, _ := os.ReadDir(outside); len(entries) != 1 {
		t.Fatalf("outside now holds %d entries", len(entries))
	}
}

func TestEditorReadsAndSavesTextFiles(t *testing.T) {
	h, home, _ := newHome(t)

	writeFile(t, filepath.Join(home, "index.php"), "<?php echo 1;", 0o640)
	writeFile(t, filepath.Join(home, "logo.png"), "\x89PNG\x00\x01", 0o644)

	content, err := h.Read("index.php")
	if err != nil {
		t.Fatal(err)
	}

	if content.Content != "<?php echo 1;" || content.Version == "" {
		t.Fatalf("content = %+v", content)
	}

	saved, err := h.Write("index.php", "<?php echo 2;", content.Version)
	if err != nil {
		t.Fatal(err)
	}

	if readFile(t, filepath.Join(home, "index.php")) != "<?php echo 2;" {
		t.Fatal("content was not saved")
	}

	if info, _ := os.Stat(filepath.Join(home, "index.php")); info.Mode().Perm() != 0o640 {
		t.Fatalf("mode = %o, want 640", info.Mode().Perm())
	}

	// Saving with the version from before the last save is refused.
	_, err = h.Write("index.php", "<?php echo 3;", content.Version)
	wantReason(t, err, ReasonChanged)

	if _, err := h.Write("index.php", "<?php echo 3;", saved.Version); err != nil {
		t.Fatal(err)
	}

	_, err = h.Read("logo.png")
	wantReason(t, err, ReasonNotText)

	_, err = h.Write("big.txt", strings.Repeat("x", MaxEditBytes+1), "")
	wantReason(t, err, ReasonTooLarge)

	if err := os.Symlink("index.php", filepath.Join(home, "alias.php")); err != nil {
		t.Fatal(err)
	}

	_, err = h.Read("alias.php")
	wantReason(t, err, ReasonLink)

	if leftovers, _ := filepath.Glob(filepath.Join(home, ".rayxo-*")); len(leftovers) != 0 {
		t.Fatalf("temporary files left: %v", leftovers)
	}
}

func TestCreateRenameAndRefusals(t *testing.T) {
	h, home, _ := newHome(t)

	if err := h.Mkdir("site"); err != nil {
		t.Fatal(err)
	}

	if err := h.CreateFile("site/a.txt"); err != nil {
		t.Fatal(err)
	}

	wantReason(t, h.CreateFile("site/a.txt"), ReasonExists)
	wantReason(t, h.Mkdir("site"), ReasonExists)
	wantReason(t, h.Mkdir("nope/deeper"), ReasonNotFound)

	if info, _ := os.Stat(filepath.Join(home, "site")); info.Mode().Perm() != 0o755 {
		t.Fatalf("folder mode = %o", info.Mode().Perm())
	}

	if err := h.CreateFile("site/b.txt"); err != nil {
		t.Fatal(err)
	}

	wantReason(t, h.Rename("site/a.txt", "b.txt"), ReasonExists)

	if err := h.Rename("site/a.txt", "c.txt"); err != nil {
		t.Fatal(err)
	}

	if _, err := os.Stat(filepath.Join(home, "site/c.txt")); err != nil {
		t.Fatal(err)
	}

	wantReason(t, h.Rename("", "x"), ReasonInvalid)
	wantReason(t, h.Mkdir(".trash/x"), ReasonTrash)
}

func TestCopyAndMove(t *testing.T) {
	h, home, _ := newHome(t)

	writeFile(t, filepath.Join(home, "src/inner/a.txt"), "A", 0o600)
	writeFile(t, filepath.Join(home, "src/b.sh"), "B", 0o755)

	if err := os.Symlink("inner/a.txt", filepath.Join(home, "src/link")); err != nil {
		t.Fatal(err)
	}

	if err := os.Chmod(filepath.Join(home, "src/inner"), 0o750); err != nil {
		t.Fatal(err)
	}

	if err := os.Mkdir(filepath.Join(home, "dest"), 0o755); err != nil {
		t.Fatal(err)
	}

	outcome := h.Copy([]string{"src"}, "dest")
	if len(outcome.Failed) != 0 || !slices.Equal(outcome.Done, []string{"src"}) {
		t.Fatalf("copy = %+v", outcome)
	}

	if readFile(t, filepath.Join(home, "dest/src/inner/a.txt")) != "A" {
		t.Fatal("file not copied")
	}

	for path, mode := range map[string]os.FileMode{"dest/src/inner/a.txt": 0o600, "dest/src/b.sh": 0o755, "dest/src/inner": 0o750} {
		if info, _ := os.Stat(filepath.Join(home, path)); info.Mode().Perm() != mode {
			t.Errorf("%s mode = %o, want %o", path, info.Mode().Perm(), mode)
		}
	}

	if target, _ := os.Readlink(filepath.Join(home, "dest/src/link")); target != "inner/a.txt" {
		t.Fatalf("link copied as %q", target)
	}

	// A folder never goes into itself, and a copy never overwrites.
	outcome = h.Copy([]string{"src", "src/b.sh"}, "src/inner")
	if len(outcome.Failed) != 1 || outcome.Failed[0].Reason != ReasonIntoItself || !slices.Equal(outcome.Done, []string{"src/b.sh"}) {
		t.Fatalf("copy = %+v", outcome)
	}

	outcome = h.Copy([]string{"src"}, "dest")
	if len(outcome.Failed) != 1 || outcome.Failed[0].Reason != ReasonExists {
		t.Fatalf("copy over an existing folder = %+v", outcome)
	}

	outcome = h.Move([]string{"src/b.sh", "missing"}, "")
	if !slices.Equal(outcome.Done, []string{"src/b.sh"}) || outcome.Failed[0].Reason != ReasonNotFound {
		t.Fatalf("move = %+v", outcome)
	}

	if readFile(t, filepath.Join(home, "b.sh")) != "B" {
		t.Fatal("file not moved")
	}

	if err := os.Mkdir(filepath.Join(home, TrashDir), 0o700); err != nil {
		t.Fatal(err)
	}

	outcome = h.Move([]string{"dest"}, TrashDir)
	if len(outcome.Failed) != 1 || outcome.Failed[0].Reason != ReasonTrash {
		t.Fatalf("move into the trash = %+v", outcome)
	}
}

func TestTrash(t *testing.T) {
	h, home, _ := newHome(t)
	now := time.Date(2026, 11, 10, 9, 30, 0, 123456789, time.UTC)
	h.now = func() time.Time { return now }

	writeFile(t, filepath.Join(home, "site/old.php"), "old", 0o644)
	writeFile(t, filepath.Join(home, "site/keep/x.txt"), "x", 0o644)

	outcome := h.Delete([]string{"site/old.php", "site/keep", "missing"})
	if len(outcome.Done) != 2 || len(outcome.Failed) != 1 {
		t.Fatalf("delete = %+v", outcome)
	}

	if _, err := os.Stat(filepath.Join(home, "site/old.php")); !os.IsNotExist(err) {
		t.Fatal("deleted file is still in place")
	}

	items, err := h.TrashList()
	if err != nil {
		t.Fatal(err)
	}

	if len(items) != 2 || !items[0].DeletedAt.Equal(now.Truncate(time.Second)) {
		t.Fatalf("trash = %+v", items)
	}

	byPath := map[string]TrashItem{}
	for _, item := range items {
		byPath[item.Path] = item
	}

	if item := byPath["site/old.php"]; item.Name != "old.php" || item.Type != TypeFile || item.Size != 3 {
		t.Fatalf("old.php in the trash = %+v", item)
	}

	// The folder it came from is gone: restoring creates it again.
	if err := os.RemoveAll(filepath.Join(home, "site")); err != nil {
		t.Fatal(err)
	}

	outcome = h.Restore([]string{byPath["site/old.php"].ID})
	if len(outcome.Failed) != 0 {
		t.Fatalf("restore = %+v", outcome)
	}

	if readFile(t, filepath.Join(home, "site/old.php")) != "old" {
		t.Fatal("not restored")
	}

	// Its place is taken: it stays in the trash.
	if err := os.MkdirAll(filepath.Join(home, "site/keep"), 0o755); err != nil {
		t.Fatal(err)
	}

	outcome = h.Restore([]string{byPath["site/keep"].ID})
	if len(outcome.Failed) != 1 || outcome.Failed[0].Reason != ReasonExists {
		t.Fatalf("restore over an existing folder = %+v", outcome)
	}

	// Read-only folders are removed too.
	if err := os.Chmod(filepath.Join(home, TrashDir, byPath["site/keep"].ID, "keep"), 0o500); err != nil {
		t.Fatal(err)
	}

	outcome = h.Purge([]string{byPath["site/keep"].ID})
	if len(outcome.Failed) != 0 {
		t.Fatalf("purge = %+v", outcome)
	}

	h.Delete([]string{"site"})

	if outcome := h.EmptyTrash(); len(outcome.Failed) != 0 || len(outcome.Done) != 1 {
		t.Fatalf("empty = %+v", outcome)
	}

	if items, _ := h.TrashList(); len(items) != 0 {
		t.Fatalf("trash after emptying = %+v", items)
	}

	if outcome := h.Delete([]string{TrashDir}); outcome.Failed[0].Reason != ReasonTrash {
		t.Fatalf("deleting the trash = %+v", outcome)
	}
}

func TestChmod(t *testing.T) {
	h, home, _ := newHome(t)

	writeFile(t, filepath.Join(home, "a.txt"), "a", 0o644)

	if err := os.Symlink("a.txt", filepath.Join(home, "link")); err != nil {
		t.Fatal(err)
	}

	outcome := h.Chmod([]string{"a.txt", "link", ""}, 0o600)
	if !slices.Equal(outcome.Done, []string{"a.txt"}) || outcome.Failed[0].Reason != ReasonLink || outcome.Failed[1].Reason != ReasonInvalid {
		t.Fatalf("chmod = %+v", outcome)
	}

	if info, _ := os.Stat(filepath.Join(home, "a.txt")); info.Mode().Perm() != 0o600 {
		t.Fatalf("mode = %o", info.Mode().Perm())
	}
}

func TestUploadAndDownloadThroughTheHelper(t *testing.T) {
	_, home, _ := newHome(t)
	transfer := t.TempDir()

	if err := os.Mkdir(filepath.Join(home, "site"), 0o755); err != nil {
		t.Fatal(err)
	}

	upload := filepath.Join(transfer, "up")
	writeFile(t, upload, "uploaded", 0o600)

	run := func(op string, params any, data *os.File) (json.RawMessage, *Error) {
		t.Helper()

		raw, _ := json.Marshal(params)
		request, _ := json.Marshal(Request{Op: op, Home: home, Params: raw})

		var out bytes.Buffer
		if err := Helper(bytes.NewReader(request), &out, data); err != nil {
			t.Fatal(err)
		}

		var reply struct {
			Result json.RawMessage `json:"result"`
			Error  *Error          `json:"error"`
		}

		if err := json.Unmarshal(out.Bytes(), &reply); err != nil {
			t.Fatal(err)
		}

		return reply.Result, reply.Error
	}

	source, err := os.Open(upload)
	if err != nil {
		t.Fatal(err)
	}

	result, failure := run(OpUpload, UploadParams{Path: "site/file.txt"}, source)
	_ = source.Close()

	if failure != nil || !strings.Contains(string(result), `"name":"file.txt"`) {
		t.Fatalf("upload = %s %v", result, failure)
	}

	if readFile(t, filepath.Join(home, "site/file.txt")) != "uploaded" {
		t.Fatal("upload not stored")
	}

	source, _ = os.Open(upload)
	_, failure = run(OpUpload, UploadParams{Path: "site/file.txt"}, source)
	_ = source.Close()

	if failure == nil || failure.Reason != ReasonExists {
		t.Fatalf("upload over an existing file = %v", failure)
	}

	target, err := os.Create(filepath.Join(transfer, "down"))
	if err != nil {
		t.Fatal(err)
	}

	_, failure = run(OpDownload, PathParams{Path: "site/file.txt"}, target)
	_ = target.Close()

	if failure != nil || readFile(t, filepath.Join(transfer, "down")) != "uploaded" {
		t.Fatalf("download = %v", failure)
	}

	_, failure = run(OpList, map[string]any{"path": "../x"}, nil)
	if failure == nil || failure.Reason != ReasonInvalid {
		t.Fatalf("list outside = %v", failure)
	}

	_, failure = run(OpList, map[string]any{"path": "", "extra": 1}, nil)
	if failure == nil || failure.Reason != ReasonInvalid {
		t.Fatalf("unknown params = %v", failure)
	}
}

func TestTransfersOnlyOpenRegularFilesAndNeverFollowLinks(t *testing.T) {
	dir := t.TempDir()
	transfers := Transfers{Dir: dir, PanelUID: os.Getuid()}
	id := strings.Repeat("a", 32)

	writeFile(t, filepath.Join(dir, "secret"), "secret", 0o600)

	if err := os.Symlink(filepath.Join(dir, "secret"), filepath.Join(dir, id+".upload")); err != nil {
		t.Fatal(err)
	}

	if _, err := transfers.OpenUpload(id); err == nil {
		t.Fatal("opened an upload that is a link")
	}

	other := strings.Repeat("b", 32)
	writeFile(t, filepath.Join(dir, other+".upload"), "data", 0o600)

	file, err := transfers.OpenUpload(other)
	if err != nil {
		t.Fatal(err)
	}

	_ = file.Close()

	download, err := transfers.CreateDownload(other)
	if err != nil {
		t.Fatal(err)
	}

	_ = download.Close()

	if _, err := transfers.CreateDownload(other); err == nil {
		t.Fatal("reused an existing download")
	}

	if err := CheckTransferID("../etc/passwd"); err == nil {
		t.Fatal("accepted a transfer id with a path")
	}
}
