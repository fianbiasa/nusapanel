package actions

import (
	"context"
	"encoding/json"
	"errors"
	"slices"
	"strings"
	"testing"

	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/sysuser"
)

// fakeFTPSystem records the folders ftp.ensure_dir asks for.
type fakeFTPSystem struct {
	paths []sysuser.Path
	fail  error
}

func (f *fakeFTPSystem) EnsurePath(_ context.Context, path sysuser.Path) error {
	f.paths = append(f.paths, path)

	return f.fail
}

func callFTP(t *testing.T, system *fakeSystem, ftp *fakeFTPSystem, params any) (json.RawMessage, error) {
	t.Helper()

	raw, err := json.Marshal(params)
	if err != nil {
		t.Fatal(err)
	}

	method := ftpMethods(system, ftp)["ftp.ensure_dir"]

	key, err := method.LockKey(raw)
	if err != nil {
		return nil, err
	}

	if key != "account:budi" {
		t.Fatalf("ftp.ensure_dir locks %q", key)
	}

	result, err := method.Handler(context.Background(), raw)
	if err != nil {
		return nil, err
	}

	encoded, _ := json.Marshal(result)

	return encoded, nil
}

func TestFTPMethodsAreRegistered(t *testing.T) {
	registry := rpc.NewRegistry()
	Register(registry)

	if !slices.Contains(registry.Names(), "ftp.ensure_dir") {
		t.Fatal("ftp.ensure_dir is not registered")
	}
}

func TestEnsureDirCreatesTheFolderAsTheAccount(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 1001)
	ftp := &fakeFTPSystem{}

	result, err := callFTP(t, system, ftp, map[string]any{"username": "budi", "directory": "domains/example.com/public_html"})
	if err != nil || string(result) != `{"uid":1001,"gid":1001,"home":"/home/budi"}` {
		t.Fatalf("result %s, %v", result, err)
	}

	want := sysuser.Path{Home: "/home/budi", Dirs: []string{"domains", "example.com", "public_html"}, UID: 1001, GID: 1001, Mode: 0o755}
	if len(ftp.paths) != 1 || !samePath(ftp.paths[0], want) {
		t.Fatalf("paths = %+v", ftp.paths)
	}
}

func TestEnsureDirOfTheHomeItself(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 1001)
	ftp := &fakeFTPSystem{}

	if _, err := callFTP(t, system, ftp, map[string]any{"username": "budi", "directory": ""}); err != nil {
		t.Fatal(err)
	}

	if len(ftp.paths) != 1 || len(ftp.paths[0].Dirs) != 0 || ftp.paths[0].Home != "/home/budi" {
		t.Fatalf("paths = %+v", ftp.paths)
	}
}

func TestEnsureDirRefusesFoldersOutsideTheHome(t *testing.T) {
	invalid := []string{
		"..", "../sari", "domains/../../etc", "/etc", "domains/", "domains//x", "./domains", "domains/.",
		"domains/a b", "domains/x;rm", "domains/\x00", strings.Repeat("a/", 17) + "a", strings.Repeat("a", 201),
	}

	for _, directory := range invalid {
		system := newFakeSystem()
		system.addAccount("budi", 1001)
		ftp := &fakeFTPSystem{}

		_, err := callFTP(t, system, ftp, map[string]any{"username": "budi", "directory": directory})
		assertCode(t, err, rpc.CodeInvalidParams)

		if len(ftp.paths) != 0 {
			t.Fatalf("%q reached the file system", directory)
		}
	}
}

func TestEnsureDirNeedsAnAccount(t *testing.T) {
	ftp := &fakeFTPSystem{}

	_, err := callFTP(t, newFakeSystem(), ftp, map[string]any{"username": "budi", "directory": "x"})
	if agentErr := assertCode(t, err, rpc.CodeActionFailed); agentErr.Message != "account budi does not exist" {
		t.Fatalf("message = %q", agentErr.Message)
	}

	if len(ftp.paths) != 0 {
		t.Fatal("a folder was made for a missing account")
	}
}

func TestEnsureDirReportsWhatIsInTheWay(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 1001)
	ftp := &fakeFTPSystem{fail: errors.Join(sysuser.ErrInTheWay, errors.New("ELOOP"))}

	_, err := callFTP(t, system, ftp, map[string]any{"username": "budi", "directory": "uploads"})
	if agentErr := assertCode(t, err, rpc.CodeActionFailed); agentErr.Message != "a file or link is in the way of folder uploads" {
		t.Fatalf("message = %q", agentErr.Message)
	}
}

func samePath(a, b sysuser.Path) bool {
	return a.Home == b.Home && slices.Equal(a.Dirs, b.Dirs) && a.UID == b.UID && a.GID == b.GID && a.Mode == b.Mode
}
