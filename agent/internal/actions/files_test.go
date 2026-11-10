package actions

import (
	"context"
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

// fakeHelper records the operations the fs.* methods run.
type fakeHelper struct {
	calls []helperCall
	// result is what every operation returns; fail fails it.
	result string
	fail   error
}

type helperCall struct {
	account files.Account
	op      string
	params  string
	data    *os.File
}

func (f *fakeHelper) Run(_ context.Context, account files.Account, op string, params any, data *os.File, result any) error {
	encoded, _ := json.Marshal(params)
	f.calls = append(f.calls, helperCall{account: account, op: op, params: string(encoded), data: data})

	if f.fail != nil {
		return f.fail
	}

	if data != nil && op == files.OpDownload {
		if _, err := data.WriteString("content"); err != nil {
			return err
		}
	}

	return json.Unmarshal([]byte(f.result), result)
}

func callFiles(t *testing.T, system *fakeSystem, helper *fakeHelper, transfers fileTransfers, method string, params any) (json.RawMessage, error) {
	t.Helper()

	raw, err := json.Marshal(params)
	if err != nil {
		t.Fatal(err)
	}

	handler := fileMethods(system, helper, transfers)[method]
	if handler.LockKey != nil {
		t.Fatalf("%s takes an account lock", method)
	}

	result, err := handler.Handler(context.Background(), raw)
	if err != nil {
		return nil, err
	}

	encoded, _ := json.Marshal(result)

	return encoded, nil
}

func TestFileMethodsAreRegistered(t *testing.T) {
	registry := rpc.NewRegistry()
	Register(registry, Config{})

	for _, name := range []string{"fs.list", "fs.read", "fs.write", "fs.upload", "fs.download", "fs.delete", "fs.trash_restore", "fs.trash_empty", "fs.compress", "fs.extract", "fs.search", "fs.disk_usage"} {
		if !slices.Contains(registry.Names(), name) {
			t.Errorf("%s is not registered", name)
		}
	}
}

func TestFileMethodsRunAsTheAccountInItsHome(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 1001)
	helper := &fakeHelper{result: `{"path":"domains","entries":[],"truncated":false}`}

	result, err := callFiles(t, system, helper, nil, "fs.list", map[string]any{"username": "budi", "path": "domains"})
	if err != nil || string(result) != helper.result {
		t.Fatalf("result %s, %v", result, err)
	}

	want := helperCall{account: files.Account{UID: 1001, GID: 1001, Home: "/home/budi"}, op: files.OpList, params: `{"path":"domains"}`}
	if len(helper.calls) != 1 || helper.calls[0] != want {
		t.Fatalf("calls = %+v", helper.calls)
	}
}

func TestFileMethodsRefuseBadParamsBeforeTheHelper(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 1001)
	helper := &fakeHelper{result: `{}`}

	for method, params := range map[string]map[string]any{
		"fs.list":     {"username": "budi", "path": "../sari"},
		"fs.rename":   {"username": "budi", "path": "a", "name": "b/c"},
		"fs.chmod":    {"username": "budi", "paths": []string{"a"}, "mode": 0o4755},
		"fs.delete":   {"username": "budi", "paths": []string{}},
		"fs.read":     {"username": "Budi", "path": "a"},
		"fs.mkdir":    {"username": "budi", "path": "a", "extra": true},
		"fs.compress": {"username": "budi", "paths": []string{"a"}, "destination": "a.rar", "format": "rar"},
		"fs.extract":  {"username": "budi", "path": "", "destination": "x"},
		"fs.search":   {"username": "budi", "path": "", "query": "../etc"},
	} {
		_, err := callFiles(t, system, helper, nil, method, params)
		assertCode(t, err, rpc.CodeInvalidParams)
	}

	if len(helper.calls) != 0 {
		t.Fatalf("bad params reached the helper: %+v", helper.calls)
	}

	_, err := callFiles(t, newFakeSystem(), helper, nil, "fs.list", map[string]any{"username": "budi", "path": ""})
	assertCode(t, err, rpc.CodeActionFailed)
}

func TestFileFailuresCarryTheirReason(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 1001)
	helper := &fakeHelper{fail: &files.Error{Reason: files.ReasonExists, Message: "site already exists"}}

	_, err := callFiles(t, system, helper, nil, "fs.mkdir", map[string]any{"username": "budi", "path": "site"})

	agentErr := assertCode(t, err, rpc.CodeActionFailed)
	if agentErr.Message != "site already exists" {
		t.Fatalf("message = %q", agentErr.Message)
	}

	if data, _ := json.Marshal(agentErr.Data); string(data) != `{"reason":"exists"}` {
		t.Fatalf("data = %s", data)
	}

	// Anything else stays in the agent log.
	helper.fail = errors.New("helper crashed at /home/budi")

	_, err = callFiles(t, system, helper, nil, "fs.mkdir", map[string]any{"username": "budi", "path": "site"})

	var rpcErr *rpc.Error
	if errors.As(err, &rpcErr) && strings.Contains(rpcErr.Message, "/home") {
		t.Fatalf("internal error leaked: %v", err)
	}
}

func TestUploadHandsTheUploadToTheHelper(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 1001)
	helper := &fakeHelper{result: `{"name":"a.txt"}`}
	dir := t.TempDir()
	transfers := files.Transfers{Dir: dir, PanelUID: os.Getuid()}
	id := strings.Repeat("u", 32)

	params := map[string]any{"username": "budi", "path": "site/a.txt", "transfer": id, "overwrite": true}

	_, err := callFiles(t, system, helper, transfers, "fs.upload", params)
	if agentErr := assertCode(t, err, rpc.CodeActionFailed); agentErr.Message != "the uploaded file could not be read" {
		t.Fatalf("message = %q", agentErr.Message)
	}

	if err := os.WriteFile(filepath.Join(dir, id+".upload"), []byte("data"), 0o600); err != nil {
		t.Fatal(err)
	}

	if _, err := callFiles(t, system, helper, transfers, "fs.upload", params); err != nil {
		t.Fatal(err)
	}

	call := helper.calls[0]
	if call.op != files.OpUpload || call.params != `{"path":"site/a.txt","overwrite":true}` || call.data == nil {
		t.Fatalf("call = %+v", call)
	}

	params["transfer"] = "../../etc/shadow"

	_, err = callFiles(t, system, helper, transfers, "fs.upload", params)
	assertCode(t, err, rpc.CodeInvalidParams)
}

func TestDownloadIsRemovedWhenItFails(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 1001)
	helper := &fakeHelper{result: `{"name":"a.txt","size":7}`}
	dir := t.TempDir()
	transfers := files.Transfers{Dir: dir, PanelUID: os.Getuid()}
	id := strings.Repeat("d", 32)

	params := map[string]any{"username": "budi", "path": "a.txt", "transfer": id}

	if _, err := callFiles(t, system, helper, transfers, "fs.download", params); err != nil {
		t.Fatal(err)
	}

	if data, _ := os.ReadFile(transfers.DownloadPath(id)); string(data) != "content" {
		t.Fatalf("download holds %q", data)
	}

	if err := os.Remove(transfers.DownloadPath(id)); err != nil {
		t.Fatal(err)
	}

	helper.fail = &files.Error{Reason: files.ReasonIsDirectory, Message: "a.txt is a folder"}

	if _, err := callFiles(t, system, helper, transfers, "fs.download", params); err == nil {
		t.Fatal("download did not fail")
	}

	if _, err := os.Stat(transfers.DownloadPath(id)); !os.IsNotExist(err) {
		t.Fatal("the failed download was left behind")
	}
}
