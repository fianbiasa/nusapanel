//go:build darwin

// Package peercred reports which user is on the other end of a Unix socket,
// as told by the kernel (it cannot be spoofed by the caller).
//
// macOS support exists only so rayxod can run next to the panel in Herd
// during development.
package peercred

import (
	"net"

	"golang.org/x/sys/unix"
)

// UID returns the user ID of the process connected on the other end of conn.
// On error it returns an ID that matches no real user, never root.
func UID(conn *net.UnixConn) (uint32, error) {
	raw, err := conn.SyscallConn()
	if err != nil {
		return noUser, err
	}

	var (
		credentials *unix.Xucred
		credErr     error
	)

	if err := raw.Control(func(fd uintptr) {
		credentials, credErr = unix.GetsockoptXucred(int(fd), unix.SOL_LOCAL, unix.LOCAL_PEERCRED)
	}); err != nil {
		return noUser, err
	}

	if credErr != nil {
		return noUser, credErr
	}

	return credentials.Uid, nil
}
