package actions

import (
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
	"rayxo/agent/internal/sitelogs"
)

// fakeLogs records the calls to the websites' logs.
type fakeLogs struct {
	calls []string
	fail  error
}

func (f *fakeLogs) Tail(user, domain, kind string, lines int, filter string) (sitelogs.Tail, error) {
	f.calls = append(f.calls, strings.Join([]string{"tail", user, domain, kind, filter}, " "))

	return sitelogs.Tail{Lines: []string{"a visit"}, Size: 9}, f.fail
}

func (f *fakeLogs) Download(user, domain, kind string, w io.Writer) error {
	f.calls = append(f.calls, strings.Join([]string{"download", user, domain, kind}, " "))
	_, _ = io.WriteString(w, "zipped log")

	return f.fail
}

func (f *fakeLogs) Report(_ context.Context, user, domain string, w io.Writer) error {
	f.calls = append(f.calls, strings.Join([]string{"report", user, domain}, " "))
	_, _ = io.WriteString(w, "<html>report</html>")

	return f.fail
}

func callLogs(t *testing.T, logs *fakeLogs, transfers fileTransfers, method string, params any) (json.RawMessage, error) {
	t.Helper()

	system := newFakeSystem()
	system.addAccount("budi", 1001)

	raw, _ := json.Marshal(params)

	registered := logMethods(system, logs, transfers)[method]
	if registered.LockKey != nil {
		t.Fatalf("%s locks the account, but only reads", method)
	}

	result, err := registered.Handler(context.Background(), raw)
	if err != nil {
		return nil, err
	}

	encoded, _ := json.Marshal(result)

	return encoded, nil
}

func TestLogMethodsAreRegistered(t *testing.T) {
	registry := rpc.NewRegistry()
	Register(registry, Config{})

	for _, name := range []string{"logs.tail", "logs.download", "stats.report"} {
		if !slices.Contains(registry.Names(), name) {
			t.Errorf("%s is not registered", name)
		}
	}
}

func TestTailReturnsTheEndOfTheLog(t *testing.T) {
	logs := &fakeLogs{}

	result, err := callLogs(t, logs, nil, "logs.tail", map[string]any{"username": "budi", "domain": "example.com", "kind": "error", "lines": 200, "filter": "php"})
	if err != nil {
		t.Fatal(err)
	}

	if !strings.Contains(string(result), `"lines":["a visit"]`) || logs.calls[0] != "tail budi example.com error php" {
		t.Errorf("result = %s, calls = %v", result, logs.calls)
	}
}

func TestLogParamsAreChecked(t *testing.T) {
	for name, params := range map[string]map[string]any{
		"no lines":       {"username": "budi", "domain": "example.com", "kind": "access", "lines": 0},
		"too many lines": {"username": "budi", "domain": "example.com", "kind": "access", "lines": sitelogs.MaxTailLines + 1},
		"another log":    {"username": "budi", "domain": "example.com", "kind": "../../shadow", "lines": 10},
		"a path":         {"username": "budi", "domain": "../etc", "kind": "access", "lines": 10},
		"a system user":  {"username": "root", "domain": "example.com", "kind": "access", "lines": 10},
	} {
		logs := &fakeLogs{}

		_, err := callLogs(t, logs, nil, "logs.tail", params)

		assertCode(t, err, rpc.CodeInvalidParams)

		if len(logs.calls) != 0 {
			t.Errorf("%s reached the logs", name)
		}
	}
}

func TestDownloadsAndReportsGoToATransferOnlyThePanelReads(t *testing.T) {
	dir := t.TempDir()
	transfers := files.Transfers{Dir: dir, PanelUID: os.Getuid()}
	download, report := strings.Repeat("l", 32), strings.Repeat("r", 32)

	if _, err := callLogs(t, &fakeLogs{}, transfers, "logs.download", map[string]any{"username": "budi", "domain": "example.com", "kind": "access", "transfer": download}); err != nil {
		t.Fatal(err)
	}

	if _, err := callLogs(t, &fakeLogs{}, transfers, "stats.report", map[string]any{"username": "budi", "domain": "example.com", "transfer": report}); err != nil {
		t.Fatal(err)
	}

	for id, want := range map[string]string{download: "zipped log", report: "<html>report</html>"} {
		content, err := os.ReadFile(filepath.Join(dir, id+".download"))
		if err != nil || string(content) != want {
			t.Errorf("transfer %s = %q, %v", id[:1], content, err)
		}
	}
}

func TestAFailedDownloadIsRemovedAndExplained(t *testing.T) {
	dir := t.TempDir()
	transfers := files.Transfers{Dir: dir, PanelUID: os.Getuid()}
	id := strings.Repeat("x", 32)

	for failure, reason := range map[error]string{
		sitelogs.ErrNoWebsite: "no_website",
		sitelogs.ErrNoVisits:  "no_visits",
	} {
		_, err := callLogs(t, &fakeLogs{fail: failure}, transfers, "stats.report", map[string]any{"username": "budi", "domain": "example.com", "transfer": id})

		agentErr := assertCode(t, err, rpc.CodeActionFailed)
		if data, _ := agentErr.Data.(map[string]string); data["reason"] != reason {
			t.Errorf("%v: data = %v", failure, agentErr.Data)
		}

		if _, err := os.Stat(filepath.Join(dir, id+".download")); !errors.Is(err, os.ErrNotExist) {
			t.Errorf("%v: the transfer is still there", failure)
		}
	}
}
