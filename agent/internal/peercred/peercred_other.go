//go:build !linux && !darwin

// Package peercred reports which user is on the other end of a Unix socket.
package peercred

import (
	"errors"
	"net"
)

// UID is not supported on this platform, so every caller is rejected.
func UID(_ *net.UnixConn) (uint32, error) {
	return noUser, errors.New("peer credentials are not supported on this platform")
}
