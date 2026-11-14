package actions

import (
	"context"
	"fmt"
	"io"
	"os"
	"time"

	"rayxo/agent/internal/cgroup"
	"rayxo/agent/internal/runner"
	"rayxo/agent/internal/sysuser"
)

// CronScopeArg is the first argument that makes rayxod cron's PAM session
// hook: /etc/pam.d/cron runs "rayxod cron-scope" through pam_exec when cron
// opens the session of a job, as root, from the process that then starts
// the job.
const CronScopeArg = "cron-scope"

// cronScopeTimeout bounds how long the hook may hold a cron job back.
const cronScopeTimeout = 5 * time.Second

// cronScope is the hook, with what it reads from its surroundings.
type cronScope struct {
	env    func(string) string
	ppid   int
	lookup func(ctx context.Context, name string) (sysuser.User, error)
	attach func(ctx context.Context, user string, pid int) error
	errors io.Writer
}

// CronScopeMain runs the hook and returns its exit status.
func CronScopeMain(_ []string) int {
	manager := cgroup.New(cgroup.Config{Root: "/sys/fs/cgroup", Proc: "/proc", Run: runner.Run})

	return cronScope{
		env:    os.Getenv,
		ppid:   os.Getppid(),
		lookup: sysuser.System{}.LookupUser,
		attach: manager.AttachCronJob,
		errors: os.Stderr,
	}.run()
}

// run moves the job of a rayxo account into the account's slice (see
// cgroup.Manager.AttachCronJob). Anything else (another service, closing
// the session, root's and other system users' jobs) passes untouched. It
// fails only when moving fails; the PAM line is optional, so the job still
// runs, outside the account's limits.
func (c cronScope) run() int {
	if c.env("PAM_TYPE") != "open_session" || c.env("PAM_SERVICE") != "cron" {
		return 0
	}

	ctx, cancel := context.WithTimeout(context.Background(), cronScopeTimeout)
	defer cancel()

	user, err := c.lookup(ctx, c.env("PAM_USER"))
	if err != nil || checkManaged(user) != nil {
		return 0
	}

	if err := c.attach(ctx, user.Name, c.ppid); err != nil {
		fmt.Fprintf(c.errors, "rayxod cron-scope: cron job of %s: %v\n", user.Name, err)

		return 1
	}

	return 0
}
