package cgroup

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// fakeSystemd answers systemctl like a server where budi has a PHP 8.4
// and a PHP 8.3 pool running.
type fakeSystemd struct {
	calls []string
	// inSlice is whether the running pools of budi are in its slice.
	inSlice bool
	fail    string
}

func (f *fakeSystemd) run(_ context.Context, program string, args ...string) ([]byte, error) {
	if program != systemctlProgram {
		return nil, errors.New("unexpected program " + program)
	}

	call := strings.Join(args, " ")
	f.calls = append(f.calls, call)

	if f.fail != "" && strings.HasPrefix(call, f.fail) {
		return nil, errors.New("systemctl failed")
	}

	switch {
	case strings.HasPrefix(call, "list-units"):
		return []byte("rayxo-php84@budi.service loaded active running PHP 8.4 FastCGI pool of budi (rayxo)\nrayxo-php83@budi.service loaded active running PHP 8.3 FastCGI pool of budi (rayxo)\n"), nil
	case strings.HasPrefix(call, "show"):
		unit := args[len(args)-1]
		if f.inSlice {
			return []byte("/rayxo.slice/rayxo-budi.slice/" + unit + "\n"), nil
		}

		return []byte("/system.slice/system-rayxo\\x2dphp84.slice/" + unit + "\n"), nil
	}

	return nil, nil
}

func newManager(t *testing.T) (*Manager, *fakeSystemd, string) {
	t.Helper()

	dir := t.TempDir()
	systemd := &fakeSystemd{}

	return New(Config{UnitDir: dir, Run: systemd.run}), systemd, dir
}

func read(t *testing.T, path string) string {
	t.Helper()

	content, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}

	return string(content)
}

func TestApplyWritesTheSliceAndTheSessionLimits(t *testing.T) {
	manager, _, dir := newManager(t)

	err := manager.Apply(context.Background(), "budi", 1001, Limits{CPUPercent: 150, MemoryMB: 512, Tasks: 100, IOWeight: 50})
	if err != nil {
		t.Fatal(err)
	}

	want := "[Slice]\nCPUQuota=150%\nMemoryMax=512M\nTasksMax=100\nIOWeight=50\n"

	slice := read(t, filepath.Join(dir, "rayxo-budi.slice"))
	if !strings.HasPrefix(slice, "[Unit]\nDescription=Hosting account budi (rayxo)\n") || !strings.HasSuffix(slice, want) {
		t.Errorf("slice = %q", slice)
	}

	if session := read(t, filepath.Join(dir, "user-1001.slice.d", "50-rayxo.conf")); !strings.HasSuffix(session, want) {
		t.Errorf("session limits = %q", session)
	}
}

func TestApplyWritesOnlyTheLimitsThatAreSet(t *testing.T) {
	manager, _, dir := newManager(t)

	if err := manager.Apply(context.Background(), "budi", 1001, Limits{MemoryMB: 256}); err != nil {
		t.Fatal(err)
	}

	slice := read(t, filepath.Join(dir, "rayxo-budi.slice"))
	if !strings.HasSuffix(slice, "[Slice]\nMemoryMax=256M\n") {
		t.Errorf("slice = %q", slice)
	}
}

func TestApplyMovesRunningPoolsIntoTheSlice(t *testing.T) {
	manager, systemd, _ := newManager(t)

	if err := manager.Apply(context.Background(), "budi", 1001, Limits{Tasks: 50}); err != nil {
		t.Fatal(err)
	}

	want := []string{
		"daemon-reload",
		"list-units --type=service --state=active --no-legend --plain rayxo-php*@budi.service",
		"show --property=ControlGroup --value rayxo-php84@budi.service",
		"try-restart rayxo-php84@budi.service",
		"show --property=ControlGroup --value rayxo-php83@budi.service",
		"try-restart rayxo-php83@budi.service",
	}

	if strings.Join(systemd.calls, "\n") != strings.Join(want, "\n") {
		t.Errorf("calls = %q", systemd.calls)
	}
}

func TestApplyLeavesPoolsAlreadyInTheSliceRunning(t *testing.T) {
	manager, systemd, _ := newManager(t)
	systemd.inSlice = true

	if err := manager.Apply(context.Background(), "budi", 1001, Limits{Tasks: 50}); err != nil {
		t.Fatal(err)
	}

	for _, call := range systemd.calls {
		if strings.HasPrefix(call, "try-restart") {
			t.Errorf("restarted a pool already in its slice: %q", call)
		}
	}
}

func TestApplyWithoutLimitsRemovesThemAndRestartsNothing(t *testing.T) {
	manager, systemd, dir := newManager(t)

	if err := manager.Apply(context.Background(), "budi", 1001, Limits{MemoryMB: 256}); err != nil {
		t.Fatal(err)
	}

	systemd.calls = nil

	if err := manager.Apply(context.Background(), "budi", 1001, Limits{}); err != nil {
		t.Fatal(err)
	}

	if strings.Join(systemd.calls, ",") != "daemon-reload" {
		t.Errorf("calls = %q", systemd.calls)
	}

	for _, path := range []string{filepath.Join(dir, "rayxo-budi.slice"), filepath.Join(dir, "user-1001.slice.d", "50-rayxo.conf")} {
		if _, err := os.Stat(path); !os.IsNotExist(err) {
			t.Errorf("%s is still there", path)
		}
	}
}

func TestRemoveDeletesTheLimitsAndReloads(t *testing.T) {
	manager, systemd, dir := newManager(t)

	if err := manager.Apply(context.Background(), "budi", 1001, Limits{CPUPercent: 100}); err != nil {
		t.Fatal(err)
	}

	systemd.calls = nil

	if err := manager.Remove(context.Background(), "budi", 1001); err != nil {
		t.Fatal(err)
	}

	if _, err := os.Stat(filepath.Join(dir, "rayxo-budi.slice")); !os.IsNotExist(err) {
		t.Error("the slice is still there")
	}

	if _, err := os.Stat(filepath.Join(dir, "user-1001.slice.d", "50-rayxo.conf")); !os.IsNotExist(err) {
		t.Error("the session limits are still there")
	}

	if strings.Join(systemd.calls, ",") != "daemon-reload" {
		t.Errorf("calls = %q", systemd.calls)
	}
}

func TestRemoveOfAnAccountWithoutLimitsSucceeds(t *testing.T) {
	manager, _, _ := newManager(t)

	if err := manager.Remove(context.Background(), "budi", 1001); err != nil {
		t.Fatal(err)
	}
}

func TestApplyRefusesBadInput(t *testing.T) {
	manager, systemd, dir := newManager(t)

	cases := map[string]struct {
		user   string
		limits Limits
	}{
		"bad username":    {"../etc", Limits{Tasks: 50}},
		"negative cpu":    {"budi", Limits{CPUPercent: -1}},
		"too much cpu":    {"budi", Limits{CPUPercent: MaxCPUPercent + 1}},
		"too little ram":  {"budi", Limits{MemoryMB: MinMemoryMB - 1}},
		"too few tasks":   {"budi", Limits{Tasks: MinTasks - 1}},
		"io weight > max": {"budi", Limits{IOWeight: MaxIOWeight + 1}},
	}

	for name, c := range cases {
		if err := manager.Apply(context.Background(), c.user, 1001, c.limits); err == nil {
			t.Errorf("%s: no error", name)
		}
	}

	if len(systemd.calls) != 0 {
		t.Errorf("ran systemctl: %q", systemd.calls)
	}

	if entries, _ := os.ReadDir(dir); len(entries) != 0 {
		t.Errorf("wrote %d files", len(entries))
	}
}

func TestApplyReportsAFailedReload(t *testing.T) {
	manager, systemd, _ := newManager(t)
	systemd.fail = "daemon-reload"

	if err := manager.Apply(context.Background(), "budi", 1001, Limits{Tasks: 50}); err == nil {
		t.Fatal("no error")
	}
}
