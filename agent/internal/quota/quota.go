// Package quota manages the disk quotas of hosting accounts: user quotas
// (space and inodes) on the filesystem that holds the homes, set and read
// with the Linux quota tools (package quota on Ubuntu 24.04). setup.sh turns
// user quotas on for that filesystem when it can; when they are off, nothing
// here changes the system.
//
// Every program is run by absolute path without a shell, and every username
// is validated again before it reaches one.
package quota

import (
	"context"
	"errors"
	"fmt"
	"io/fs"
	"log/slog"
	"slices"
	"strconv"
	"strings"

	"rayxo/agent/internal/runner"
	"rayxo/agent/internal/validate"
)

// Programs used, by absolute path as the quota package installs them on
// Ubuntu 24.04 (quotaon in /sbin, the others in /usr/sbin).
const (
	quotaonProgram  = "/sbin/quotaon"
	setquotaProgram = "/usr/sbin/setquota"
	repquotaProgram = "/usr/sbin/repquota"
)

// MaxBlocksKB and MaxInodes are the largest limits the vfsv1 quota format
// takes: the kernel keeps space limits as a signed 64-bit number of bytes,
// and setquota refuses an inode count of 2^63-1 as an overflow.
const (
	MaxBlocksKB = int64(1<<63-1) >> 10
	MaxInodes   = int64(1<<63 - 2)
)

// Limits are the limits of one user. Zero means unlimited. Soft and hard
// limits are the same, so there is no grace period.
type Limits struct {
	// BlocksKB limits disk space, in KiB (quota blocks of 1 KiB).
	BlocksKB int64
	// Inodes limits the number of files and directories.
	Inodes int64
}

// Status says whether the filesystem that holds the homes enforces user
// quotas.
type Status struct {
	// MountPoint is where that filesystem is mounted, such as "/".
	MountPoint string
	// Enabled reports whether user quotas are on there.
	Enabled bool
	// Reason explains, for the log, why they are off.
	Reason string
}

// Usage is what one user uses on the filesystem and that user's hard
// limits, where zero means none. Space is in KiB.
type Usage struct {
	UID          int
	BlocksKB     int64
	Inodes       int64
	BlockLimitKB int64
	InodeLimit   int64
}

// Report is the quota state of the filesystem that holds the homes. Users
// is empty when user quotas are off.
type Report struct {
	Status
	Users []Usage
}

// Config holds what the Manager needs from the system, so tests can run
// without root and without the quota tools.
type Config struct {
	// HomeRoot holds the account homes; quotas apply to its filesystem.
	HomeRoot string
	// MountPoint returns the mount point of the filesystem holding path.
	MountPoint func(path string) (string, error)
	// Run runs a program without a shell, like runner.Run.
	Run func(ctx context.Context, program string, args ...string) ([]byte, error)
	// Logger receives why limits were not applied. Defaults to
	// slog.Default().
	Logger *slog.Logger
}

// DefaultConfig returns what is used on a server.
func DefaultConfig() Config {
	return Config{
		HomeRoot:   "/home",
		MountPoint: mountPoint,
		Run:        runner.Run,
	}
}

// Manager sets and reports user quotas. It keeps no state: every call
// checks the live quota state, so quotas turned on or off by hand (or by a
// later setup.sh) are picked up right away.
type Manager struct {
	cfg Config
}

// New returns a Manager for cfg. It does not touch the system.
func New(cfg Config) *Manager {
	return &Manager{cfg: cfg}
}

// Status finds the filesystem that holds the homes and reports whether user
// quotas are on there. Quotas that are off, including when the quota tools
// are not installed, are not an error.
func (m *Manager) Status(ctx context.Context) (Status, error) {
	mountPoint, err := m.cfg.MountPoint(m.cfg.HomeRoot)
	if err != nil {
		return Status{}, fmt.Errorf("find the filesystem of %s: %w", m.cfg.HomeRoot, err)
	}

	status := Status{MountPoint: mountPoint}

	// quotaon -p exits with the number of quota types that are on, so 1
	// means "on"; only its output tells "on" from a failure.
	output, err := m.cfg.Run(ctx, quotaonProgram, "-p", "-u", mountPoint)
	if errors.Is(err, fs.ErrNotExist) {
		status.Reason = "the quota tools are not installed"

		return status, nil
	}

	if err != nil && runner.ExitCode(err) < 0 {
		return Status{}, fmt.Errorf("check the user quota state of %s: %w", mountPoint, err)
	}

	status.Enabled, status.Reason, err = parseQuotaState(output, mountPoint)
	if err != nil {
		return Status{}, err
	}

	return status, nil
}

// SetUserLimits sets the limits of user on the filesystem that holds the
// homes. When user quotas are off there it changes nothing, logs why and
// returns false.
func (m *Manager) SetUserLimits(ctx context.Context, user string, limits Limits) (applied bool, err error) {
	if err := checkLimits(user, limits); err != nil {
		return false, err
	}

	status, err := m.Status(ctx)
	if err != nil {
		return false, err
	}

	if !status.Enabled {
		m.logger().Info("disk quota not applied: user quotas are off",
			"user", user, "filesystem", status.MountPoint, "reason", status.Reason)

		return false, nil
	}

	if err := m.setQuota(ctx, status.MountPoint, user, limits); err != nil {
		return false, err
	}

	return true, nil
}

// ClearUserLimits removes every limit of user, so a later user that gets
// the same UID does not inherit them. It is meant for a user that is about
// to be deleted: it does nothing when user quotas are off, and failures are
// only logged, because they must not keep the user from being deleted.
func (m *Manager) ClearUserLimits(ctx context.Context, user string) {
	status, err := m.Status(ctx)
	if err == nil && status.Enabled {
		err = m.setQuota(ctx, status.MountPoint, user, Limits{})
	}

	if err != nil {
		m.logger().Warn("disk quota not cleared", "user", user, "error", err)
	}
}

// Report returns the quota state of the filesystem that holds the homes and,
// when user quotas are on, the usage and limits of every user with a quota
// entry there.
func (m *Manager) Report(ctx context.Context) (Report, error) {
	status, err := m.Status(ctx)
	if err != nil || !status.Enabled {
		return Report{Status: status}, err
	}

	// -v lists users without usage too, so limits set before an account
	// stored anything are reported; -n prints UIDs instead of names.
	output, err := m.cfg.Run(ctx, repquotaProgram, "-u", "-v", "-n", "-O", "csv", status.MountPoint)
	if err != nil {
		return Report{}, fmt.Errorf("report user quotas of %s: %w", status.MountPoint, err)
	}

	users, skipped, err := parseReport(output)
	if err != nil {
		return Report{}, fmt.Errorf("report user quotas of %s: %w", status.MountPoint, err)
	}

	if len(skipped) > 0 {
		m.logger().Warn("ignored unexpected lines of the repquota output",
			"filesystem", status.MountPoint, "count", len(skipped), "first", skipped[0])
	}

	return Report{Status: status, Users: users}, nil
}

// setQuota sets user's limits on mountPoint, soft and hard alike.
func (m *Manager) setQuota(ctx context.Context, mountPoint, user string, limits Limits) error {
	if err := checkLimits(user, limits); err != nil {
		return err
	}

	blocks := strconv.FormatInt(limits.BlocksKB, 10)
	inodes := strconv.FormatInt(limits.Inodes, 10)

	_, err := m.cfg.Run(ctx, setquotaProgram, "-u", "--", user, blocks, blocks, inodes, inodes, mountPoint)
	if err != nil {
		return fmt.Errorf("set the disk quota of %s on %s: %w", user, mountPoint, err)
	}

	return nil
}

func (m *Manager) logger() *slog.Logger {
	if m.cfg.Logger != nil {
		return m.cfg.Logger
	}

	return slog.Default()
}

// checkLimits validates user and limits again before they reach setquota.
func checkLimits(user string, limits Limits) error {
	if err := validate.Username(user); err != nil {
		return err
	}

	if limits.BlocksKB < 0 || limits.BlocksKB > MaxBlocksKB {
		return fmt.Errorf("disk limit %d KiB is out of range", limits.BlocksKB)
	}

	if limits.Inodes < 0 || limits.Inodes > MaxInodes {
		return fmt.Errorf("inode limit %d is out of range", limits.Inodes)
	}

	return nil
}

// parseQuotaState reads the output of `quotaon -p -u <mountPoint>` (quota
// 4.06, quotaon.c print_state), which prints
//
//	user quota on <mountPoint> (<device>) is on
//
// or "is off". A filesystem mounted without any quota option is not listed
// at all; quotaon then only complains on stderr that it was "not found or
// has no quota enabled".
func parseQuotaState(output []byte, mountPoint string) (enabled bool, reason string, err error) {
	prefix := "user quota on " + mountPoint + " ("

	for line := range strings.Lines(string(output)) {
		line = strings.TrimSpace(line)
		if !strings.HasPrefix(line, prefix) {
			continue
		}

		switch {
		case strings.HasSuffix(line, ") is on"):
			return true, "", nil
		case strings.HasSuffix(line, ") is off"):
			return false, "user quotas are off on " + mountPoint, nil
		}
	}

	if strings.Contains(string(output), "has no quota enabled") {
		return false, mountPoint + " is not mounted with user quota options", nil
	}

	return false, "", fmt.Errorf("unexpected output of quotaon for %s: %q", mountPoint, strings.TrimSpace(string(output)))
}

// reportColumns are the columns of the CSV report that are read, in the
// order of reportLine's values.
var reportColumns = []string{"BlockUsed", "BlockHardLimit", "FileUsed", "FileHardLimit"}

// reportLayout is where the columns of a CSV report are, from its header.
type reportLayout struct {
	width   int
	indexes []int
}

// parseReport reads the output of `repquota -u -n -O csv` (quota 4.06,
// repquota.c report_it and print): a header line
//
//	User,BlockStatus,FileStatus,BlockUsed,BlockSoftLimit,BlockHardLimit,BlockGrace,FileUsed,FileSoftLimit,FileHardLimit,FileGrace
//
// then one line per user, named "#<uid>" because of -n, with space in KiB
// and 0 for "no limit"; grace columns may be empty. Columns are found by
// their header name. Lines that do not belong to the report, such as
// warnings written to stderr, are returned as skipped.
func parseReport(output []byte) (users []Usage, skipped []string, err error) {
	var layout *reportLayout

	for line := range strings.Lines(string(output)) {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}

		fields := strings.Split(line, ",")

		if fields[0] == "User" {
			if layout, err = parseReportHeader(fields); err != nil {
				return nil, nil, err
			}

			continue
		}

		usage, ok := Usage{}, false
		if layout != nil {
			usage, ok = layout.parseLine(fields)
		}

		if !ok {
			skipped = append(skipped, line)

			continue
		}

		users = append(users, usage)
	}

	if layout == nil {
		return nil, nil, fmt.Errorf("no CSV header in the repquota output: %q", strings.TrimSpace(string(output)))
	}

	return users, skipped, nil
}

// parseReportHeader finds reportColumns in the header of a CSV report.
func parseReportHeader(fields []string) (*reportLayout, error) {
	layout := &reportLayout{width: len(fields)}

	for _, name := range reportColumns {
		index := slices.Index(fields, name)
		if index < 0 {
			return nil, fmt.Errorf("the repquota CSV header has no column %s: %q", name, strings.Join(fields, ","))
		}

		layout.indexes = append(layout.indexes, index)
	}

	return layout, nil
}

// parseLine parses one user line of a CSV report.
func (l *reportLayout) parseLine(fields []string) (Usage, bool) {
	if len(fields) != l.width {
		return Usage{}, false
	}

	id, isID := strings.CutPrefix(fields[0], "#")
	uid, err := strconv.ParseUint(id, 10, 32)
	if !isID || err != nil {
		return Usage{}, false
	}

	values := make([]int64, len(l.indexes))
	for i, index := range l.indexes {
		value, err := strconv.ParseInt(fields[index], 10, 64)
		if err != nil {
			return Usage{}, false
		}

		values[i] = max(value, 0)
	}

	return Usage{
		UID:          int(uid),
		BlocksKB:     values[0],
		BlockLimitKB: values[1],
		Inodes:       values[2],
		InodeLimit:   values[3],
	}, true
}
