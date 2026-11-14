package cgroup

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"rayxo/agent/internal/validate"
)

const busctlProgram = "/usr/bin/busctl"

// cronAttachWait is how long AttachCronJob waits for systemd to move the
// process, and how often it looks.
const (
	cronAttachWait = 3 * time.Second
	cronAttachPoll = 10 * time.Millisecond
)

// ErrNotMoved is returned when systemd took the scope but the process is
// not in the account's slice in time.
var ErrNotMoved = errors.New("the process did not reach the account's slice in time")

// AttachCronJob moves the cron process pid, which runs a job of the account
// user, into a scope of its own in the account's slice, so the job gets the
// account's limits and its CPU time counts as the account's. cron calls the
// hook from the process that then starts the job, so the job and the mail
// of its output follow it. It returns once the process is there; the scope
// goes away by itself when the job is done.
func (m *Manager) AttachCronJob(ctx context.Context, user string, pid int) error {
	if err := validate.Username(user); err != nil {
		return err
	}

	if pid <= 1 {
		return fmt.Errorf("pid %d is not a cron job", pid)
	}

	scope := "rayxo-cron-" + user + "-" + strconv.Itoa(pid) + ".scope"

	if _, err := m.cfg.Run(ctx, busctlProgram, "call",
		"org.freedesktop.systemd1", "/org/freedesktop/systemd1", "org.freedesktop.systemd1.Manager",
		"StartTransientUnit", "ssa(sv)a(sa(sv))", scope, "fail",
		"4",
		"PIDs", "au", "1", strconv.Itoa(pid),
		"Slice", "s", sliceName(user),
		"Description", "s", "Cron job of "+user+" (rayxo)",
		// The process the memory limit kills is enough: the rest of the
		// job, and cron mailing its output, go on.
		"OOMPolicy", "s", "continue",
		"0",
	); err != nil {
		return fmt.Errorf("start %s: %w", scope, err)
	}

	// systemd queues the scope; the job must not start before it is in.
	want := "/rayxo.slice/" + sliceName(user) + "/"
	deadline := time.Now().Add(cronAttachWait)

	for {
		current, err := os.ReadFile(filepath.Join(m.cfg.Proc, strconv.Itoa(pid), "cgroup"))
		if err != nil {
			return fmt.Errorf("read the cgroup of %d: %w", pid, err)
		}

		if strings.Contains(string(current), want) {
			return nil
		}

		if time.Now().After(deadline) {
			return ErrNotMoved
		}

		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(cronAttachPoll):
		}
	}
}
