package actions

import (
	"context"
	"encoding/json"
	"slices"
	"testing"

	"rayxo/agent/internal/procmon"
	"rayxo/agent/internal/rpc"
)

// fakeMonitor records the calls to the process monitor.
type fakeMonitor struct {
	listed []int
	killed []string
	fail   error
}

func (f *fakeMonitor) List(uid int) (procmon.Snapshot, error) {
	f.listed = append(f.listed, uid)

	return procmon.Snapshot{Processes: []procmon.Process{}, Users: []procmon.UserUsage{}, CPUs: 2}, nil
}

func (f *fakeMonitor) Kill(pid int, start, signal string) error {
	f.killed = append(f.killed, signal)

	return f.fail
}

func callProcess(t *testing.T, monitor *fakeMonitor, method string, params any) error {
	t.Helper()

	system := newFakeSystem()
	system.addAccount("budi", 1001)

	raw, _ := json.Marshal(params)

	registered := processMethods(system, monitor)[method]
	if registered.LockKey != nil {
		t.Fatalf("%s takes a lock", method)
	}

	_, err := registered.Handler(context.Background(), raw)

	return err
}

func TestProcessMethodsAreRegistered(t *testing.T) {
	registry := rpc.NewRegistry()
	Register(registry, Config{})

	for _, name := range []string{"process.list", "process.kill"} {
		if !slices.Contains(registry.Names(), name) {
			t.Errorf("%s is not registered", name)
		}
	}
}

func TestProcessListShowsTheServerOrOneAccount(t *testing.T) {
	monitor := &fakeMonitor{}

	if err := callProcess(t, monitor, "process.list", map[string]any{}); err != nil {
		t.Fatal(err)
	}

	if err := callProcess(t, monitor, "process.list", map[string]any{"username": "budi"}); err != nil {
		t.Fatal(err)
	}

	if !slices.Equal(monitor.listed, []int{-1, 1001}) {
		t.Errorf("listed uids %v", monitor.listed)
	}

	assertCode(t, callProcess(t, monitor, "process.list", map[string]any{"username": "root"}), rpc.CodeInvalidParams)
	assertCode(t, callProcess(t, monitor, "process.list", map[string]any{"username": "sari"}), rpc.CodeActionFailed)
}

func TestProcessKillExplainsWhyItCouldNot(t *testing.T) {
	assertCode(t, callProcess(t, &fakeMonitor{}, "process.kill", map[string]any{"pid": 100, "start": "500", "signal": "HUP"}), rpc.CodeInvalidParams)
	assertCode(t, callProcess(t, &fakeMonitor{}, "process.kill", map[string]any{"pid": 100, "signal": "TERM"}), rpc.CodeInvalidParams)

	for failure, reason := range map[error]string{procmon.ErrGone: "gone", procmon.ErrProtected: "protected"} {
		agentErr := assertCode(t, callProcess(t, &fakeMonitor{fail: failure}, "process.kill", map[string]any{"pid": 100, "start": "500", "signal": "KILL"}), rpc.CodeActionFailed)

		if data, _ := agentErr.Data.(map[string]string); data["reason"] != reason {
			t.Errorf("%v: data %v", failure, agentErr.Data)
		}
	}

	monitor := &fakeMonitor{}
	if err := callProcess(t, monitor, "process.kill", map[string]any{"pid": 100, "start": "500", "signal": "TERM"}); err != nil || !slices.Equal(monitor.killed, []string{"TERM"}) {
		t.Errorf("kill = %v, %v", err, monitor.killed)
	}
}
