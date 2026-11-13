// Package cgroup limits what a hosting account may use of the server with
// cgroups v2, through systemd slices: CPU time, memory, the number of
// processes and its share of disk IO.
//
// The PHP-FPM pools of an account run in rayxo-<user>.slice (their unit
// template says Slice=rayxo-%i.slice), and its SSH sessions in the
// user-<uid>.slice systemd-logind makes; both get the same limits. Cron
// jobs run in cron.service and are not limited.
package cgroup

import (
	"context"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"strconv"
	"strings"

	"rayxo/agent/internal/validate"
)

const systemctlProgram = "/usr/bin/systemctl"

// Bounds of the limits.
const (
	MaxCPUPercent = 100000
	MaxMemoryMB   = 16 << 20
	MaxTasks      = 1 << 22
	MaxIOWeight   = 10000
	// MinMemoryMB is the least memory a limit may leave: PHP needs some.
	MinMemoryMB = 32
	// MinTasks is the fewest processes a limit may leave.
	MinTasks = 10
)

// Limits are what an account may use; 0 is unlimited.
type Limits struct {
	// CPUPercent is CPU time in percent of one CPU: 200 is two CPUs.
	CPUPercent int
	MemoryMB   int
	Tasks      int
	// IOWeight is its share of disk IO, 1 to 10000; 0 leaves systemd's
	// default of 100.
	IOWeight int
}

// Check reports what is wrong with the limits.
func (l Limits) Check() error {
	switch {
	case l.CPUPercent < 0 || l.CPUPercent > MaxCPUPercent:
		return fmt.Errorf("cpu_percent must be 0 (unlimited) or 1 to %d", MaxCPUPercent)
	case l.MemoryMB != 0 && (l.MemoryMB < MinMemoryMB || l.MemoryMB > MaxMemoryMB):
		return fmt.Errorf("memory_mb must be 0 (unlimited) or %d to %d", MinMemoryMB, MaxMemoryMB)
	case l.Tasks != 0 && (l.Tasks < MinTasks || l.Tasks > MaxTasks):
		return fmt.Errorf("tasks must be 0 (unlimited) or %d to %d", MinTasks, MaxTasks)
	case l.IOWeight < 0 || l.IOWeight > MaxIOWeight:
		return fmt.Errorf("io_weight must be 0 (the default) or 1 to %d", MaxIOWeight)
	}

	return nil
}

// None reports whether no limit is set.
func (l Limits) None() bool {
	return l == Limits{}
}

// Config holds where the Manager writes and how it runs systemctl.
type Config struct {
	// UnitDir is /etc/systemd/system.
	UnitDir string
	// Root is where cgroups are mounted, /sys/fs/cgroup.
	Root string
	Run  func(ctx context.Context, program string, args ...string) ([]byte, error)
}

// Manager writes the slices of accounts. Callers make sure only one call
// works on an account at a time (the agent's account lock).
type Manager struct {
	cfg Config
}

// New returns a Manager for cfg.
func New(cfg Config) *Manager {
	return &Manager{cfg: cfg}
}

// Apply gives the account's slices the limits, then moves its running PHP
// pools into its slice: a pool that started before its unit named the
// slice stays outside until it restarts. Without limits it removes them,
// and restarts nothing.
func (m *Manager) Apply(ctx context.Context, user string, uid int, limits Limits) error {
	if err := validate.Username(user); err != nil {
		return err
	}

	if err := limits.Check(); err != nil {
		return err
	}

	if limits.None() {
		return m.Remove(ctx, user, uid)
	}

	settings := sliceSettings(limits)
	unit := "[Unit]\nDescription=Hosting account " + user + " (rayxo)\n\n" + settings

	if err := writeFile(filepath.Join(m.cfg.UnitDir, sliceName(user)), unit); err != nil {
		return err
	}

	if err := writeFile(m.userDropIn(uid), settings); err != nil {
		return err
	}

	if _, err := m.cfg.Run(ctx, systemctlProgram, "daemon-reload"); err != nil {
		return fmt.Errorf("reload systemd: %w", err)
	}

	return m.moveIntoSlice(ctx, user)
}

// Remove deletes the account's slice settings; a uid of 0 or less leaves
// the session limits, for an account whose user is already gone.
func (m *Manager) Remove(ctx context.Context, user string, uid int) error {
	if err := validate.Username(user); err != nil {
		return err
	}

	if err := m.removeFiles(user, uid); err != nil {
		return err
	}

	if _, err := m.cfg.Run(ctx, systemctlProgram, "daemon-reload"); err != nil {
		return fmt.Errorf("reload systemd: %w", err)
	}

	return nil
}

// moveIntoSlice restarts the running PHP pools of user that are not in its
// slice yet. rayxo-<user>.slice is a child of rayxo.slice, as systemd nests
// slices by the dashes in their names; usernames have no dashes.
func (m *Manager) moveIntoSlice(ctx context.Context, user string) error {
	output, err := m.cfg.Run(ctx, systemctlProgram, "list-units", "--type=service", "--state=active", "--no-legend", "--plain", "rayxo-php*@"+user+".service")
	if err != nil {
		return fmt.Errorf("list the PHP pools of %s: %w", user, err)
	}

	for line := range strings.Lines(string(output)) {
		fields := strings.Fields(line)
		if len(fields) == 0 || !strings.HasPrefix(fields[0], "rayxo-php") || !strings.HasSuffix(fields[0], "@"+user+".service") {
			continue
		}

		unit := fields[0]

		// The cgroup the pool runs in, not its Slice= setting: after a
		// daemon-reload that setting names the new slice while the running
		// pool is still where it started.
		group, err := m.cfg.Run(ctx, systemctlProgram, "show", "--property=ControlGroup", "--value", unit)
		if err != nil {
			return fmt.Errorf("check the cgroup of %s: %w", unit, err)
		}

		if strings.HasPrefix(strings.TrimSpace(string(group)), "/rayxo.slice/"+sliceName(user)+"/") {
			continue
		}

		if _, err := m.cfg.Run(ctx, systemctlProgram, "try-restart", unit); err != nil {
			return fmt.Errorf("restart %s into its slice: %w", unit, err)
		}
	}

	return nil
}

func (m *Manager) removeFiles(user string, uid int) error {
	paths := []string{filepath.Join(m.cfg.UnitDir, sliceName(user))}
	if uid > 0 {
		paths = append(paths, m.userDropIn(uid))
	}

	for _, path := range paths {
		if err := os.Remove(path); err != nil && !errors.Is(err, fs.ErrNotExist) {
			return fmt.Errorf("remove %s: %w", path, err)
		}
	}

	return nil
}

func (m *Manager) userDropIn(uid int) string {
	return filepath.Join(m.cfg.UnitDir, "user-"+strconv.Itoa(uid)+".slice.d", "50-rayxo.conf")
}

func sliceName(user string) string {
	return "rayxo-" + user + ".slice"
}

// writeFile replaces path with content in one step.
func writeFile(path, content string) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return err
	}

	temp := path + ".rayxo-tmp"

	if err := os.WriteFile(temp, []byte(content), 0o644); err != nil {
		return fmt.Errorf("write %s: %w", path, err)
	}

	if err := os.Rename(temp, path); err != nil {
		_ = os.Remove(temp)

		return fmt.Errorf("write %s: %w", path, err)
	}

	return nil
}

// sliceSettings is the [Slice] section with the limits that are set.
func sliceSettings(l Limits) string {
	var settings strings.Builder

	settings.WriteString("# Written by rayxod from the account's package; changes are overwritten.\n[Slice]\n")

	if l.CPUPercent > 0 {
		settings.WriteString("CPUQuota=" + strconv.Itoa(l.CPUPercent) + "%\n")
	}

	if l.MemoryMB > 0 {
		settings.WriteString("MemoryMax=" + strconv.Itoa(l.MemoryMB) + "M\n")
	}

	if l.Tasks > 0 {
		settings.WriteString("TasksMax=" + strconv.Itoa(l.Tasks) + "\n")
	}

	if l.IOWeight > 0 {
		settings.WriteString("IOWeight=" + strconv.Itoa(l.IOWeight) + "\n")
	}

	return settings.String()
}
