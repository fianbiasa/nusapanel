//go:build unix

package sysuser

import (
	"errors"
	"io/fs"
	"os"
	"path/filepath"
	"syscall"
	"testing"
)

// testHome describes a home owned by the user running the tests, the only
// owner a test without root may set.
func testHome(path string) Home {
	return Home{
		Path:      path,
		Mode:      0o710,
		UID:       os.Getuid(),
		AccessGID: os.Getgid(),
		GID:       os.Getgid(),
		Dirs: []Dir{
			{Name: "domains", Mode: 0o711},
			{Name: "tmp", Mode: 0o700},
			{Name: ".trash", Mode: 0o700},
		},
	}
}

func TestPrepareHomeCreatesTheLayout(t *testing.T) {
	home := testHome(filepath.Join(t.TempDir(), "budi"))

	if err := prepareHome(home); err != nil {
		t.Fatalf("prepare failed: %v", err)
	}

	assertDir(t, home.Path, 0o710)

	for _, dir := range home.Dirs {
		assertDir(t, filepath.Join(home.Path, dir.Name), dir.Mode)
	}
}

func TestPrepareHomeRepairsExistingDirectories(t *testing.T) {
	home := testHome(filepath.Join(t.TempDir(), "budi"))
	mustMkdir(t, home.Path, 0o755)
	mustMkdir(t, filepath.Join(home.Path, "tmp"), 0o777)

	if err := prepareHome(home); err != nil {
		t.Fatalf("prepare failed: %v", err)
	}

	assertDir(t, home.Path, 0o710)
	assertDir(t, filepath.Join(home.Path, "tmp"), 0o700)
}

func TestPrepareHomeDoesNotFollowSymlinks(t *testing.T) {
	outside := filepath.Join(t.TempDir(), "etc")
	mustMkdir(t, outside, 0o755)

	home := testHome(filepath.Join(t.TempDir(), "budi"))
	mustMkdir(t, home.Path, 0o710)

	if err := os.Symlink(outside, filepath.Join(home.Path, "domains")); err != nil {
		t.Fatal(err)
	}

	if err := prepareHome(home); err == nil {
		t.Fatal("expected an error for a symlink in place of a directory")
	}

	assertMode(t, outside, 0o755)
}

func TestPrepareHomeRefusesASymlinkedHome(t *testing.T) {
	outside := filepath.Join(t.TempDir(), "elsewhere")
	mustMkdir(t, outside, 0o755)

	home := testHome(filepath.Join(t.TempDir(), "budi"))
	if err := os.Symlink(outside, home.Path); err != nil {
		t.Fatal(err)
	}

	if err := prepareHome(home); err == nil {
		t.Fatal("expected an error for a symlinked home")
	}

	assertMode(t, outside, 0o755)
}

func TestPrepareHomeRefusesFilesInPlaceOfDirectories(t *testing.T) {
	home := testHome(filepath.Join(t.TempDir(), "budi"))
	mustMkdir(t, home.Path, 0o710)

	if err := os.WriteFile(filepath.Join(home.Path, "tmp"), []byte("x"), 0o600); err != nil {
		t.Fatal(err)
	}

	if err := prepareHome(home); err == nil {
		t.Fatal("expected an error for a file in place of a directory")
	}
}

func TestPrepareHomeRefusesUnsafePaths(t *testing.T) {
	base := t.TempDir()

	for _, home := range []Home{
		testHome("home/budi"),
		testHome(base + "/budi/../budi"),
		{Path: filepath.Join(base, "budi"), Mode: 0o710, Dirs: []Dir{{Name: "../escape", Mode: 0o700}}},
		{Path: filepath.Join(base, "sari"), Mode: 0o710, Dirs: []Dir{{Name: "..", Mode: 0o700}}},
	} {
		home.UID, home.GID, home.AccessGID = os.Getuid(), os.Getgid(), os.Getgid()

		if err := prepareHome(home); err == nil {
			t.Errorf("expected an error for %+v", home)
		}
	}

	if _, err := os.Stat(filepath.Join(base, "escape")); err == nil {
		t.Fatal("a directory was created outside the home")
	}
}

// testSite describes domains/example.com/public_html in home, owned by the
// user running the tests.
func testSite(home string) Site {
	return Site{
		Home: home,
		Dirs: []Dir{
			{Name: "domains", Mode: 0o711},
			{Name: "example.com", Mode: 0o711},
			{Name: "public_html", Mode: 0o755},
		},
		UID:   os.Getuid(),
		GID:   os.Getgid(),
		Index: []byte("<h1>ready</h1>\n"),
	}
}

func TestPrepareSiteCreatesTheFoldersAndIndex(t *testing.T) {
	home := filepath.Join(t.TempDir(), "budi")
	mustMkdir(t, home, 0o710)

	if err := prepareSite(testSite(home)); err != nil {
		t.Fatalf("prepare failed: %v", err)
	}

	assertDir(t, filepath.Join(home, "domains"), 0o711)
	assertDir(t, filepath.Join(home, "domains/example.com"), 0o711)
	assertDir(t, filepath.Join(home, "domains/example.com/public_html"), 0o755)

	index := filepath.Join(home, "domains/example.com/public_html/index.html")
	assertFile(t, index, "<h1>ready</h1>\n", 0o644)
}

func TestPrepareSiteKeepsExistingFiles(t *testing.T) {
	home := filepath.Join(t.TempDir(), "budi")
	mustMkdir(t, home, 0o710)
	site := testSite(home)

	if err := prepareSite(site); err != nil {
		t.Fatalf("first prepare failed: %v", err)
	}

	public := filepath.Join(home, "domains/example.com/public_html")
	if err := os.WriteFile(filepath.Join(public, "index.html"), []byte("my site"), 0o600); err != nil {
		t.Fatal(err)
	}

	if err := os.Chmod(filepath.Join(public, "index.html"), 0o600); err != nil {
		t.Fatal(err)
	}

	if err := os.Chmod(public, 0o700); err != nil {
		t.Fatal(err)
	}

	if err := prepareSite(site); err != nil {
		t.Fatalf("second prepare failed: %v", err)
	}

	assertFile(t, filepath.Join(public, "index.html"), "my site", 0o600)
	assertDir(t, public, 0o755)

	// A site whose owner deleted index.html does not get it back.
	if err := os.Remove(filepath.Join(public, "index.html")); err != nil {
		t.Fatal(err)
	}

	if err := prepareSite(site); err != nil {
		t.Fatalf("third prepare failed: %v", err)
	}

	if _, err := os.Lstat(filepath.Join(public, "index.html")); !os.IsNotExist(err) {
		t.Fatalf("index.html was written again: %v", err)
	}
}

func TestPrepareSiteDoesNotFollowSymlinks(t *testing.T) {
	for _, linked := range []string{"domains", "domains/example.com", "domains/example.com/public_html"} {
		outside := filepath.Join(t.TempDir(), "etc")
		mustMkdir(t, outside, 0o755)

		home := filepath.Join(t.TempDir(), "budi")
		mustMkdir(t, home, 0o710)

		if dir := filepath.Dir(linked); dir != "." {
			if err := os.MkdirAll(filepath.Join(home, dir), 0o711); err != nil {
				t.Fatal(err)
			}
		}

		if err := os.Symlink(outside, filepath.Join(home, linked)); err != nil {
			t.Fatal(err)
		}

		err := prepareSite(testSite(home))
		if !errors.Is(err, ErrInTheWay) {
			t.Fatalf("symlink at %s: got %v, want ErrInTheWay", linked, err)
		}

		assertMode(t, outside, 0o755)

		entries, err := os.ReadDir(outside)
		if err != nil || len(entries) != 0 {
			t.Fatalf("symlink at %s: something was created outside the home: %v %v", linked, entries, err)
		}
	}
}

func testPath(home string, dirs ...string) Path {
	return Path{Home: home, Dirs: dirs, UID: os.Getuid(), GID: os.Getgid(), Mode: 0o755}
}

func TestEnsurePathCreatesTheMissingFolders(t *testing.T) {
	home := filepath.Join(t.TempDir(), "budi")
	mustMkdir(t, home, 0o710)

	if err := ensurePath(testPath(home, "domains", "example.com", "uploads")); err != nil {
		t.Fatal(err)
	}

	assertDir(t, filepath.Join(home, "domains/example.com/uploads"), 0o755)
}

func TestEnsurePathLeavesExistingFoldersAlone(t *testing.T) {
	home := filepath.Join(t.TempDir(), "budi")
	mustMkdir(t, home, 0o710)
	mustMkdir(t, filepath.Join(home, "private"), 0o700)

	if err := ensurePath(testPath(home, "private", "backups")); err != nil {
		t.Fatal(err)
	}

	assertMode(t, filepath.Join(home, "private"), 0o700)
	assertDir(t, filepath.Join(home, "private/backups"), 0o755)

	// The home itself is a valid folder too.
	if err := ensurePath(testPath(home)); err != nil {
		t.Fatal(err)
	}

	assertMode(t, home, 0o710)
}

func TestEnsurePathDoesNotFollowSymlinks(t *testing.T) {
	outside := filepath.Join(t.TempDir(), "etc")
	mustMkdir(t, outside, 0o755)

	home := filepath.Join(t.TempDir(), "budi")
	mustMkdir(t, home, 0o710)

	if err := os.Symlink(outside, filepath.Join(home, "linked")); err != nil {
		t.Fatal(err)
	}

	if err := ensurePath(testPath(home, "linked", "x")); !errors.Is(err, ErrInTheWay) {
		t.Fatalf("got %v, want ErrInTheWay", err)
	}

	if entries, _ := os.ReadDir(outside); len(entries) != 0 {
		t.Fatalf("something was created outside the home: %v", entries)
	}
}

func TestEnsurePathRefusesUnsafeNames(t *testing.T) {
	home := filepath.Join(t.TempDir(), "budi")
	mustMkdir(t, home, 0o710)

	for _, dirs := range [][]string{{".."}, {"."}, {""}, {"a/b"}} {
		if err := ensurePath(testPath(home, dirs...)); err == nil {
			t.Errorf("%q was accepted", dirs)
		}
	}

	if err := ensurePath(testPath("budi", "x")); err == nil {
		t.Error("a relative home was accepted")
	}
}

func TestPrepareSiteRefusesFilesInPlaceOfFolders(t *testing.T) {
	home := filepath.Join(t.TempDir(), "budi")
	mustMkdir(t, home, 0o710)
	mustMkdir(t, filepath.Join(home, "domains"), 0o711)

	if err := os.WriteFile(filepath.Join(home, "domains/example.com"), []byte("x"), 0o600); err != nil {
		t.Fatal(err)
	}

	if err := prepareSite(testSite(home)); !errors.Is(err, ErrInTheWay) {
		t.Fatalf("got %v, want ErrInTheWay", err)
	}
}

func TestPrepareSiteRefusesASymlinkedHome(t *testing.T) {
	outside := filepath.Join(t.TempDir(), "elsewhere")
	mustMkdir(t, outside, 0o755)

	home := filepath.Join(t.TempDir(), "budi")
	if err := os.Symlink(outside, home); err != nil {
		t.Fatal(err)
	}

	if err := prepareSite(testSite(home)); err == nil {
		t.Fatal("expected an error for a symlinked home")
	}

	if entries, _ := os.ReadDir(outside); len(entries) != 0 {
		t.Fatalf("something was created through the symlink: %v", entries)
	}
}

func TestPrepareSiteRefusesUnsafeNames(t *testing.T) {
	base := t.TempDir()
	home := filepath.Join(base, "budi")
	mustMkdir(t, home, 0o710)

	for _, name := range []string{"", ".", "..", "../escape", "a/b"} {
		site := testSite(home)
		site.Dirs[1].Name = name

		if err := prepareSite(site); err == nil {
			t.Errorf("expected an error for directory name %q", name)
		}
	}

	for _, path := range []string{"home/budi", home + "/../budi"} {
		site := testSite(path)

		if err := prepareSite(site); err == nil {
			t.Errorf("expected an error for home %q", path)
		}
	}

	if _, err := os.Stat(filepath.Join(base, "escape")); err == nil {
		t.Fatal("a directory was created outside the home")
	}
}

// assertFile checks that path is a regular file with content and mode,
// owned by the user running the tests.
func assertFile(t *testing.T, path, content string, mode fs.FileMode) {
	t.Helper()

	info, err := os.Lstat(path)
	if err != nil {
		t.Fatalf("stat %s: %v", path, err)
	}

	if !info.Mode().IsRegular() || info.Mode().Perm() != mode {
		t.Fatalf("%s has mode %v, want a file with %v", path, info.Mode(), mode)
	}

	stat := info.Sys().(*syscall.Stat_t)
	if int(stat.Uid) != os.Getuid() || int(stat.Gid) != os.Getgid() {
		t.Fatalf("%s is owned by %d:%d, want %d:%d", path, stat.Uid, stat.Gid, os.Getuid(), os.Getgid())
	}

	data, err := os.ReadFile(path)
	if err != nil || string(data) != content {
		t.Fatalf("%s contains %q (%v), want %q", path, data, err, content)
	}
}

// assertDir checks that path is a directory with mode, owned by the user
// running the tests.
func assertDir(t *testing.T, path string, mode fs.FileMode) {
	t.Helper()

	info := assertMode(t, path, mode)

	stat := info.Sys().(*syscall.Stat_t)
	if int(stat.Uid) != os.Getuid() || int(stat.Gid) != os.Getgid() {
		t.Fatalf("%s is owned by %d:%d, want %d:%d", path, stat.Uid, stat.Gid, os.Getuid(), os.Getgid())
	}
}

// assertMode checks that path is a directory with mode.
func assertMode(t *testing.T, path string, mode fs.FileMode) fs.FileInfo {
	t.Helper()

	info, err := os.Lstat(path)
	if err != nil {
		t.Fatalf("stat %s: %v", path, err)
	}

	if !info.IsDir() || info.Mode().Perm() != mode {
		t.Fatalf("%s has mode %v, want a directory with %v", path, info.Mode(), mode)
	}

	return info
}

func mustMkdir(t *testing.T, path string, mode fs.FileMode) {
	t.Helper()

	if err := os.Mkdir(path, mode); err != nil {
		t.Fatal(err)
	}

	if err := os.Chmod(path, mode); err != nil {
		t.Fatal(err)
	}
}
