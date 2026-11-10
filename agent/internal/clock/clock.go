// Package clock reads and sets the server's timezone, which cron
// schedules and logs follow.
package clock

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strings"

	"rayxo/agent/internal/runner"
)

// The programs the timezone is changed with; the agent's runner only runs
// absolute paths.
const (
	timedatectlProgram = "/usr/bin/timedatectl"
	systemctlProgram   = "/usr/bin/systemctl"
)

// ErrUnknownTimezone means the name is not a timezone of this server.
var ErrUnknownTimezone = errors.New("unknown timezone")

// timezonePattern is the shape of an IANA timezone name, such as
// "Asia/Jakarta", "America/Argentina/Buenos_Aires" or "Etc/GMT+7". It keeps
// anything that is not a plain name away from timedatectl.
var timezonePattern = regexp.MustCompile(`^[A-Za-z][A-Za-z0-9_+-]*(/[A-Za-z0-9_+-]+){0,2}$`)

// Config holds where the timezone lives and how programs run.
type Config struct {
	// LocaltimeFile is the link to the current timezone in ZoneinfoDir.
	LocaltimeFile string
	ZoneinfoDir   string
	// CronUnit is restarted after a change: cron reads the timezone only
	// when it starts.
	CronUnit string
	// Run runs a program without a shell, like runner.Run.
	Run func(ctx context.Context, program string, args ...string) ([]byte, error)
}

// DefaultConfig returns the locations and operations used on a server.
func DefaultConfig() Config {
	return Config{
		LocaltimeFile: "/etc/localtime",
		ZoneinfoDir:   "/usr/share/zoneinfo",
		CronUnit:      "cron.service",
		Run:           runner.Run,
	}
}

// Manager reads and sets the timezone.
type Manager struct {
	cfg Config
}

// New returns a Manager for cfg. It does not touch the system.
func New(cfg Config) *Manager {
	return &Manager{cfg: cfg}
}

// Timezone returns the server's timezone, such as "Asia/Jakarta", or ""
// when the local time is not a link into the zoneinfo folder.
func (m *Manager) Timezone() string {
	link, err := os.Readlink(m.cfg.LocaltimeFile)
	if err != nil {
		return ""
	}

	if !filepath.IsAbs(link) {
		link = filepath.Join(filepath.Dir(m.cfg.LocaltimeFile), link)
	}

	name, ok := strings.CutPrefix(filepath.Clean(link), filepath.Clean(m.cfg.ZoneinfoDir)+"/")
	if !ok {
		return ""
	}

	return name
}

// SetTimezone makes timezone the server's. It must be one timedatectl
// knows (else ErrUnknownTimezone). Cron is restarted so its schedules
// follow; the same timezone again changes nothing.
func (m *Manager) SetTimezone(ctx context.Context, timezone string) error {
	if !timezonePattern.MatchString(timezone) {
		return fmt.Errorf("%w: %q", ErrUnknownTimezone, timezone)
	}

	list, err := m.cfg.Run(ctx, timedatectlProgram, "list-timezones")
	if err != nil {
		return fmt.Errorf("list timezones: %w", err)
	}

	if !slices.Contains(strings.Fields(string(list)), timezone) {
		return fmt.Errorf("%w: %q", ErrUnknownTimezone, timezone)
	}

	if m.Timezone() == timezone {
		return nil
	}

	if _, err := m.cfg.Run(ctx, timedatectlProgram, "set-timezone", timezone); err != nil {
		return fmt.Errorf("set the timezone to %s: %w", timezone, err)
	}

	if _, err := m.cfg.Run(ctx, systemctlProgram, "try-restart", m.cfg.CronUnit); err != nil {
		return fmt.Errorf("restart %s: %w", m.cfg.CronUnit, err)
	}

	return nil
}
