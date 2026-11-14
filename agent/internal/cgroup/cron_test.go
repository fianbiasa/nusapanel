package cgroup

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// cronEnv fakes /proc and systemd: once busctl is called, the process
// shows the cgroup moveTo (or keeps cron's when moveTo is empty).
func cronEnv(t *testing.T, moveTo string) (*Manager, *[]string) {
	t.Helper()

	proc := t.TempDir()
	cgroupFile := filepath.Join(proc, "4242", "cgroup")

	if err := os.MkdirAll(filepath.Dir(cgroupFile), 0o755); err != nil {
		t.Fatal(err)
	}

	if err := os.WriteFile(cgroupFile, []byte("0::/system.slice/cron.service\n"), 0o644); err != nil {
		t.Fatal(err)
	}

	var calls []string

	manager := New(Config{Proc: proc, Run: func(_ context.Context, program string, args ...string) ([]byte, error) {
		calls = append(calls, program+" "+strings.Join(args, " "))

		if moveTo != "" {
			return nil, os.WriteFile(cgroupFile, []byte("0::"+moveTo+"\n"), 0o644)
		}

		return nil, nil
	}})

	return manager, &calls
}

func TestACronJobMovesIntoTheAccountsSlice(t *testing.T) {
	manager, calls := cronEnv(t, "/rayxo.slice/rayxo-budi.slice/rayxo-cron-budi-4242.scope")

	if err := manager.AttachCronJob(context.Background(), "budi", 4242); err != nil {
		t.Fatal(err)
	}

	want := "/usr/bin/busctl call org.freedesktop.systemd1 /org/freedesktop/systemd1 org.freedesktop.systemd1.Manager StartTransientUnit ssa(sv)a(sa(sv)) rayxo-cron-budi-4242.scope fail 4 PIDs au 1 4242 Slice s rayxo-budi.slice Description s Cron job of budi (rayxo) OOMPolicy s continue 0"
	if len(*calls) != 1 || (*calls)[0] != want {
		t.Errorf("calls = %q", *calls)
	}
}

func TestACronJobThatDoesNotMoveIsReported(t *testing.T) {
	manager, _ := cronEnv(t, "")
	ctx, cancel := context.WithCancel(context.Background())
	cancel()

	err := manager.AttachCronJob(ctx, "budi", 4242)
	if !errors.Is(err, context.Canceled) {
		t.Errorf("err = %v", err)
	}
}

func TestCronJobsOfOtherNamesOrProcessesAreRefused(t *testing.T) {
	manager, calls := cronEnv(t, "/rayxo.slice/rayxo-budi.slice/x.scope")

	for _, c := range []struct {
		user string
		pid  int
	}{{"../etc", 4242}, {"budi", 1}, {"budi", 0}} {
		if err := manager.AttachCronJob(context.Background(), c.user, c.pid); err == nil {
			t.Errorf("%s %d: no error", c.user, c.pid)
		}
	}

	if len(*calls) != 0 {
		t.Errorf("calls = %q", *calls)
	}
}
