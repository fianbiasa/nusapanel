//go:build !linux

package sysuser

import (
	"context"
	"errors"
	"fmt"
	"runtime"
)

// System is not available outside Linux: every method fails with an error
// wrapping errors.ErrUnsupported.
type System struct{}

func unsupported() error {
	return fmt.Errorf("managing users on %s: %w", runtime.GOOS, errors.ErrUnsupported)
}

// LookupUser is not supported on this platform.
func (System) LookupUser(context.Context, string) (User, error) {
	return User{}, unsupported()
}

// ListUsers is not supported on this platform.
func (System) ListUsers(context.Context) ([]User, error) {
	return nil, unsupported()
}

// EnsureGroupMember is not supported on this platform.
func (System) EnsureGroupMember(context.Context, string, string) (int, error) {
	return 0, unsupported()
}

// AddUser is not supported on this platform.
func (System) AddUser(context.Context, NewUser) error {
	return unsupported()
}

// SetPasswordLock is not supported on this platform.
func (System) SetPasswordLock(context.Context, string, bool) error {
	return unsupported()
}

// SetShell is not supported on this platform.
func (System) SetShell(context.Context, string, string) error {
	return unsupported()
}

// KillProcesses is not supported on this platform.
func (System) KillProcesses(context.Context, int) error {
	return unsupported()
}

// DeleteUser is not supported on this platform.
func (System) DeleteUser(context.Context, string) error {
	return unsupported()
}

// PrepareHome is not supported on this platform.
func (System) PrepareHome(context.Context, Home) error {
	return unsupported()
}

// PrepareSite is not supported on this platform.
func (System) PrepareSite(context.Context, Site) error {
	return unsupported()
}

// SetCrontab is not supported on this platform.
func (System) SetCrontab(context.Context, string, []byte) error {
	return unsupported()
}

// EnsurePath is not supported on this platform.
func (System) EnsurePath(context.Context, Path) error {
	return unsupported()
}
