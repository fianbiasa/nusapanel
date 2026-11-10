package clock

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
)

// fakeSystem plays timedatectl and systemctl: set-timezone moves the
// localtime link, the way timedatectl does.
type fakeSystem struct {
	t     *testing.T
	cfg   Config
	calls []string
}

func newFakeSystem(t *testing.T, current string) *fakeSystem {
	t.Helper()

	base := t.TempDir()
	system := &fakeSystem{t: t}
	system.cfg = Config{
		LocaltimeFile: filepath.Join(base, "etc/localtime"),
		ZoneinfoDir:   filepath.Join(base, "usr/share/zoneinfo"),
		CronUnit:      "cron.service",
		Run:           system.run,
	}

	if err := os.MkdirAll(filepath.Dir(system.cfg.LocaltimeFile), 0o755); err != nil {
		t.Fatal(err)
	}

	system.link(current)

	return system
}

func (f *fakeSystem) link(timezone string) {
	f.t.Helper()

	_ = os.Remove(f.cfg.LocaltimeFile)

	if err := os.Symlink(filepath.Join(f.cfg.ZoneinfoDir, timezone), f.cfg.LocaltimeFile); err != nil {
		f.t.Fatal(err)
	}
}

func (f *fakeSystem) run(_ context.Context, program string, args ...string) ([]byte, error) {
	f.calls = append(f.calls, strings.Join(append([]string{program}, args...), " "))

	if program == "/usr/bin/timedatectl" && args[0] == "list-timezones" {
		return []byte("Asia/Jakarta\nAsia/Makassar\nEtc/GMT+7\nUTC\n"), nil
	}

	if program == "/usr/bin/timedatectl" && args[0] == "set-timezone" {
		f.link(args[1])
	}

	return nil, nil
}

func TestTimezoneIsWhereTheLocaltimeLinkPoints(t *testing.T) {
	system := newFakeSystem(t, "Asia/Jakarta")

	if got := New(system.cfg).Timezone(); got != "Asia/Jakarta" {
		t.Fatalf("Timezone() = %q, want Asia/Jakarta", got)
	}

	if err := os.Remove(system.cfg.LocaltimeFile); err != nil {
		t.Fatal(err)
	}

	if err := os.WriteFile(system.cfg.LocaltimeFile, []byte("TZif"), 0o644); err != nil {
		t.Fatal(err)
	}

	if got := New(system.cfg).Timezone(); got != "" {
		t.Fatalf("a copied localtime: Timezone() = %q, want empty", got)
	}
}

func TestSetTimezoneChangesItAndRestartsCron(t *testing.T) {
	system := newFakeSystem(t, "UTC")
	manager := New(system.cfg)

	if err := manager.SetTimezone(context.Background(), "Asia/Jakarta"); err != nil {
		t.Fatal(err)
	}

	want := []string{"/usr/bin/timedatectl list-timezones", "/usr/bin/timedatectl set-timezone Asia/Jakarta", "/usr/bin/systemctl try-restart cron.service"}
	if !slices.Equal(system.calls, want) {
		t.Fatalf("calls = %q, want %q", system.calls, want)
	}

	if got := manager.Timezone(); got != "Asia/Jakarta" {
		t.Fatalf("Timezone() = %q after the change", got)
	}

	// The same timezone again leaves cron running.
	system.calls = nil

	if err := manager.SetTimezone(context.Background(), "Asia/Jakarta"); err != nil {
		t.Fatal(err)
	}

	if !slices.Equal(system.calls, []string{"/usr/bin/timedatectl list-timezones"}) {
		t.Fatalf("calls = %q, want only the list", system.calls)
	}
}

func TestSetTimezoneRefusesNamesTheServerDoesNotKnow(t *testing.T) {
	for _, name := range []string{"Mars/Olympus", "", "../../etc/passwd", "Asia/Jakarta;reboot", "-h", "/UTC", "Asia//Jakarta"} {
		system := newFakeSystem(t, "UTC")

		err := New(system.cfg).SetTimezone(context.Background(), name)
		if !errors.Is(err, ErrUnknownTimezone) {
			t.Fatalf("SetTimezone(%q) = %v, want ErrUnknownTimezone", name, err)
		}

		if slices.ContainsFunc(system.calls, func(call string) bool { return strings.Contains(call, "set-timezone") }) {
			t.Fatalf("SetTimezone(%q) changed the timezone: %q", name, system.calls)
		}
	}
}
