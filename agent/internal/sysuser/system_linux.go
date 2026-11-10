//go:build linux

package sysuser

import (
	"context"
	"errors"
	"fmt"
	"slices"
	"strconv"
	"strings"
	"time"

	"rayxo/agent/internal/runner"
)

// Programs used, by absolute path as installed on Ubuntu 24.04.
const (
	getentProgram   = "/usr/bin/getent"
	groupaddProgram = "/usr/sbin/groupadd"
	gpasswdProgram  = "/usr/bin/gpasswd"
	useraddProgram  = "/usr/sbin/useradd"
	usermodProgram  = "/usr/sbin/usermod"
	userdelProgram  = "/usr/sbin/userdel"
	pkillProgram    = "/usr/bin/pkill"
	crontabProgram  = "/usr/bin/crontab"
	chpasswdProgram = "/usr/sbin/chpasswd"
)

// Exit codes documented by those programs.
const (
	getentKeyNotFound = 2 // one or more keys were not found
	useraddNameInUse  = 9 // the user or group name is already in use
	userdelNoSuchUser = 6 // the user does not exist
	pkillNoMatch      = 1 // no process matched
)

// killTimeout bounds how long KillProcesses keeps killing processes that
// were started while it ran.
const killTimeout = 15 * time.Second

// System manages local users with the standard shadow-utils and procps
// programs. Names are passed as separate arguments after "--", never through
// a shell.
type System struct{}

// LookupUser returns the user called name, or ErrNotFound. It asks NSS
// through getent, so accounts outside /etc/passwd are found too.
func (System) LookupUser(ctx context.Context, name string) (User, error) {
	output, err := runner.Run(ctx, getentProgram, "passwd", "--", name)
	if runner.ExitCode(err) == getentKeyNotFound {
		return User{}, fmt.Errorf("user %s: %w", name, ErrNotFound)
	}

	if err != nil {
		return User{}, fmt.Errorf("look up user %s: %w", name, err)
	}

	user, err := parsePasswd(firstLine(output))
	if err != nil {
		return User{}, fmt.Errorf("look up user %s: %w", name, err)
	}

	if user.Name != name {
		return User{}, fmt.Errorf("look up user %s: getent returned user %s", name, user.Name)
	}

	return user, nil
}

// ListUsers returns every user NSS knows, in the order getent lists them.
func (System) ListUsers(ctx context.Context) ([]User, error) {
	output, err := runner.Run(ctx, getentProgram, "passwd")
	if err != nil {
		return nil, fmt.Errorf("list users: %w", err)
	}

	users, err := parsePasswdList(output)
	if err != nil {
		return nil, fmt.Errorf("list users: %w", err)
	}

	return users, nil
}

// EnsureGroupMember creates group as a system group when it is missing, adds
// member to it when needed, and returns the group's GID.
func (s System) EnsureGroupMember(ctx context.Context, group, member string) (int, error) {
	found, err := s.lookupGroup(ctx, group)
	if errors.Is(err, ErrNotFound) {
		if _, err := runner.Run(ctx, groupaddProgram, "--system", "--", group); err != nil {
			return 0, fmt.Errorf("create group %s: %w", group, err)
		}

		found, err = s.lookupGroup(ctx, group)
	}

	if err != nil {
		return 0, err
	}

	if !slices.Contains(found.Members, member) {
		if _, err := runner.Run(ctx, gpasswdProgram, "--add", member, "--", group); err != nil {
			return 0, fmt.Errorf("add %s to group %s: %w", member, group, err)
		}
	}

	return found.GID, nil
}

// AddUser creates a regular user with its own group and home directory. It
// returns an error wrapping ErrNameTaken when the user or group name exists.
func (System) AddUser(ctx context.Context, user NewUser) error {
	_, err := runner.Run(ctx, useraddProgram,
		"--create-home",
		"--home-dir", user.Home,
		"--shell", user.Shell,
		"--comment", user.Comment,
		"--user-group",
		"--", user.Name,
	)
	if runner.ExitCode(err) == useraddNameInUse {
		return fmt.Errorf("create user %s: %w: %w", user.Name, ErrNameTaken, err)
	}

	if err != nil {
		return fmt.Errorf("create user %s: %w", user.Name, err)
	}

	return nil
}

// SetPasswordLock locks or unlocks the account. Locking also expires the
// account, because a locked password alone does not stop SSH-key or SFTP
// logins; PAM refuses every login to an expired account. Unlocking a user
// that never had a password leaves the password locked; usermod only warns.
func (System) SetPasswordLock(ctx context.Context, name string, locked bool) error {
	args, action := []string{"--unlock", "--expiredate", ""}, "unlock"
	if locked {
		args, action = []string{"--lock", "--expiredate", "1"}, "lock"
	}

	if _, err := runner.Run(ctx, usermodProgram, append(args, "--", name)...); err != nil {
		return fmt.Errorf("%s password of %s: %w", action, name, err)
	}

	return nil
}

// SetShell changes the user's login shell.
func (System) SetShell(ctx context.Context, name, shell string) error {
	if _, err := runner.Run(ctx, usermodProgram, "--shell", shell, "--", name); err != nil {
		return fmt.Errorf("set shell of %s: %w", name, err)
	}

	return nil
}

// KillProcesses kills every process whose real or effective UID is uid, and
// repeats until none is left, so processes forked in the meantime are
// caught too. userdel refuses to remove a user that still has processes.
func (System) KillProcesses(ctx context.Context, uid int) error {
	ctx, cancel := context.WithTimeout(ctx, killTimeout)
	defer cancel()

	id := strconv.Itoa(uid)

	for {
		matchedEffective, err := pkill(ctx, "--euid", id)
		if err != nil {
			return err
		}

		matchedReal, err := pkill(ctx, "--uid", id)
		if err != nil {
			return err
		}

		if !matchedEffective && !matchedReal {
			return nil
		}

		select {
		case <-ctx.Done():
			return fmt.Errorf("processes of uid %d are still running: %w", uid, ctx.Err())
		case <-time.After(100 * time.Millisecond):
		}
	}
}

// pkill sends SIGKILL to the processes matching selector and reports
// whether any matched.
func pkill(ctx context.Context, selector, id string) (bool, error) {
	_, err := runner.Run(ctx, pkillProgram, "--signal", "KILL", selector, id)
	if runner.ExitCode(err) == pkillNoMatch {
		return false, nil
	}

	if err != nil {
		return false, fmt.Errorf("kill processes of uid %s: %w", id, err)
	}

	return true, nil
}

// DeleteUser removes the user, its home directory and mail spool. Deleting a
// user that does not exist succeeds.
func (System) DeleteUser(ctx context.Context, name string) error {
	_, err := runner.Run(ctx, userdelProgram, "--remove", "--", name)
	if runner.ExitCode(err) == userdelNoSuchUser {
		return nil
	}

	if err != nil {
		return fmt.Errorf("delete user %s: %w", name, err)
	}

	return nil
}

// PrepareHome creates home and its directories when missing and sets their
// owners and modes, without following symlinks.
func (System) PrepareHome(_ context.Context, home Home) error {
	return prepareHome(home)
}

// PrepareSite creates the folders of a website inside a home, and its
// default index.html, without following symlinks.
func (System) PrepareSite(_ context.Context, site Site) error {
	return prepareSite(site)
}

// SetPassword sets the password hash of user name as it is (chpasswd -e),
// such as a SHA512-CRYPT hash or "!" for no password. The hash goes on
// stdin, so it never shows up in the process list.
func (System) SetPassword(ctx context.Context, name, hash string) error {
	if strings.ContainsAny(name+hash, ":\n") {
		return fmt.Errorf("set password of %s: name and hash may not hold ':' or newlines", name)
	}

	if _, err := runner.RunInput(ctx, []byte(name+":"+hash+"\n"), chpasswdProgram, "-e"); err != nil {
		return fmt.Errorf("set password of %s: %w", name, err)
	}

	return nil
}

// SetCrontab installs crontab as the crontab of user name, which the cron
// daemon runs as that user, or removes the user's crontab when crontab is
// empty. Removing a crontab that does not exist succeeds.
func (System) SetCrontab(ctx context.Context, name string, crontab []byte) error {
	if len(crontab) == 0 {
		_, err := runner.Run(ctx, crontabProgram, "-u", name, "-r")

		var runErr *runner.Error
		if errors.As(err, &runErr) && strings.Contains(runErr.Output, "no crontab for") {
			return nil
		}

		if err != nil {
			return fmt.Errorf("remove the crontab of %s: %w", name, err)
		}

		return nil
	}

	if _, err := runner.RunInput(ctx, crontab, crontabProgram, "-u", name, "-"); err != nil {
		return fmt.Errorf("install the crontab of %s: %w", name, err)
	}

	return nil
}

// EnsurePath creates the missing folders of path inside a home, without
// following symlinks or changing folders that already exist.
func (System) EnsurePath(_ context.Context, path Path) error {
	return ensurePath(path)
}

func (System) lookupGroup(ctx context.Context, name string) (Group, error) {
	output, err := runner.Run(ctx, getentProgram, "group", "--", name)
	if runner.ExitCode(err) == getentKeyNotFound {
		return Group{}, fmt.Errorf("group %s: %w", name, ErrNotFound)
	}

	if err != nil {
		return Group{}, fmt.Errorf("look up group %s: %w", name, err)
	}

	group, err := parseGroup(firstLine(output))
	if err != nil {
		return Group{}, fmt.Errorf("look up group %s: %w", name, err)
	}

	if group.Name != name {
		return Group{}, fmt.Errorf("look up group %s: getent returned group %s", name, group.Name)
	}

	return group, nil
}
