package reconfigure

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"
)

// fakeSystemd records calls; active says whether the unit runs.
type fakeSystemd struct {
	calls  [][]string
	active bool
}

func (f *fakeSystemd) run(_ context.Context, program string, args ...string) ([]byte, error) {
	f.calls = append(f.calls, append([]string{program}, args...))

	if program == systemctlProgram && !f.active {
		return nil, errors.New("exit status 3")
	}

	return nil, nil
}

func newManager(t *testing.T) (*Manager, *fakeSystemd, Config) {
	t.Helper()

	dir := t.TempDir()
	systemd := &fakeSystemd{}
	cfg := Config{
		Script:    "/usr/local/rayxo/current/setup.sh",
		Unit:      "rayxo-reconfigure",
		LogFile:   filepath.Join(dir, "reconfigure.log"),
		StateFile: filepath.Join(dir, "reconfigure.json"),
		Run:       systemd.run,
		Now:       func() time.Time { return time.Date(2026, 11, 11, 8, 0, 0, 0, time.UTC) },
	}

	return New(cfg), systemd, cfg
}

func TestStartRunsSetupInItsOwnUnit(t *testing.T) {
	manager, systemd, cfg := newManager(t)

	if err := os.WriteFile(cfg.LogFile, []byte("old output\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	if err := manager.Start(context.Background(), Request{Hostname: "server2.example.net", PanelPort: 2223}); err != nil {
		t.Fatal(err)
	}

	want := []string{
		systemdRunProgram, "--unit=rayxo-reconfigure", "--collect", "--quiet", "--description=RAYXO: configure the server again",
		"/bin/sh", "-c", script, "rayxo-reconfigure", cfg.LogFile, cfg.StateFile + ".exit",
		cfg.Script, "--reconfigure", "--yes", "--hostname", "server2.example.net", "--panel-port", "2223",
	}
	if got := systemd.calls[len(systemd.calls)-1]; !slices.Equal(got, want) {
		t.Fatalf("systemd-run call =\n  %q\nwant\n  %q", got, want)
	}

	if _, err := os.Stat(cfg.LogFile); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("the previous run's output is still there: %v", err)
	}

	if info, err := os.Stat(cfg.StateFile); err != nil || info.Mode().Perm() != 0o600 {
		t.Fatalf("state file: %v %v, want mode 0600", info, err)
	}

	systemd.active = true

	state, err := manager.Status(context.Background())
	if err != nil || state == nil || !state.Running || state.Hostname != "server2.example.net" || state.PanelPort != 2223 ||
		!state.StartedAt.Equal(time.Date(2026, 11, 11, 8, 0, 0, 0, time.UTC)) || state.ExitCode != nil {
		t.Fatalf("status while running = %+v, %v", state, err)
	}
}

func TestStartPassesOnlyWhatChanges(t *testing.T) {
	manager, systemd, cfg := newManager(t)

	if err := manager.Start(context.Background(), Request{PanelPort: 8443}); err != nil {
		t.Fatal(err)
	}

	call := systemd.calls[len(systemd.calls)-1]
	if got := call[len(call)-5:]; !slices.Equal(got, []string{cfg.Script, "--reconfigure", "--yes", "--panel-port", "8443"}) {
		t.Fatalf("setup.sh arguments = %q", got)
	}
}

func TestStartRefusesWhileSetupRuns(t *testing.T) {
	manager, systemd, _ := newManager(t)
	systemd.active = true

	if err := manager.Start(context.Background(), Request{Hostname: "server2.example.net"}); !errors.Is(err, ErrRunning) {
		t.Fatalf("err = %v, want ErrRunning", err)
	}

	for _, call := range systemd.calls {
		if call[0] == systemdRunProgram {
			t.Fatal("systemd-run ran while setup.sh was running")
		}
	}
}

func TestStatusReportsWhySetupStopped(t *testing.T) {
	manager, _, cfg := newManager(t)

	if state, err := manager.Status(context.Background()); state != nil || err != nil {
		t.Fatalf("status before any run = %+v, %v; want nil", state, err)
	}

	if err := manager.Start(context.Background(), Request{Hostname: "server2.example.net"}); err != nil {
		t.Fatal(err)
	}

	output := "==> Memeriksa syarat DNS (A record & PTR)\n\n" +
		"GAGAL: A record server2.example.net belum ada. Tambahkan A record server2.example.net -> 203.0.113.10\n"
	if err := os.WriteFile(cfg.LogFile, []byte(output+strings.Repeat("line\n", 60)), 0o600); err != nil {
		t.Fatal(err)
	}

	if err := os.WriteFile(cfg.StateFile+".exit", []byte("1\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	state, err := manager.Status(context.Background())
	if err != nil {
		t.Fatal(err)
	}

	if state.Running || state.ExitCode == nil || *state.ExitCode != 1 || len(state.Log) != logLines {
		t.Fatalf("unexpected state %+v", state)
	}

	// Only the lines shown count; this failure scrolled out of the tail.
	if state.Error != "" {
		t.Fatalf("Error = %q from a line no longer shown", state.Error)
	}

	if err := os.WriteFile(cfg.LogFile, []byte(output), 0o600); err != nil {
		t.Fatal(err)
	}

	state, _ = manager.Status(context.Background())
	if state.Error != "A record server2.example.net belum ada. Tambahkan A record server2.example.net -> 203.0.113.10" {
		t.Fatalf("Error = %q", state.Error)
	}
}
