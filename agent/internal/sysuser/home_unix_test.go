//go:build unix

package sysuser

import (
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
