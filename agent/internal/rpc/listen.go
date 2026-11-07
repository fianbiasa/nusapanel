//go:build linux || darwin

package rpc

import (
	"errors"
	"fmt"
	"io/fs"
	"net"
	"os"

	"golang.org/x/sys/unix"
)

// Listen creates the agent socket at path with the given mode, giving it to
// group gid (gid < 0 keeps the current group). A stale socket from a previous
// run is removed; any other kind of file at path is an error.
//
// The socket is created under a restrictive umask and only then opened up to
// mode, so no other user can connect before the permissions are final. The
// umask is process-wide, so call Listen during startup before other goroutines
// create files.
func Listen(path string, mode os.FileMode, gid int) (*net.UnixListener, error) {
	info, err := os.Lstat(path)

	switch {
	case err == nil && info.Mode().Type() != fs.ModeSocket:
		return nil, fmt.Errorf("%s exists and is not a socket", path)
	case err == nil:
		if err := os.Remove(path); err != nil {
			return nil, fmt.Errorf("remove stale socket: %w", err)
		}
	case !errors.Is(err, fs.ErrNotExist):
		return nil, err
	}

	previousUmask := unix.Umask(0o177)
	listener, err := net.ListenUnix("unix", &net.UnixAddr{Name: path, Net: "unix"})
	unix.Umask(previousUmask)

	if err != nil {
		return nil, err
	}

	listener.SetUnlinkOnClose(true)

	if gid >= 0 {
		if err := os.Chown(path, -1, gid); err != nil {
			_ = listener.Close()

			return nil, fmt.Errorf("set socket group: %w", err)
		}
	}

	if err := os.Chmod(path, mode); err != nil {
		_ = listener.Close()

		return nil, fmt.Errorf("set socket mode: %w", err)
	}

	return listener, nil
}
