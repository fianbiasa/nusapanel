//go:build linux

package procmon

import (
	"os"
	"syscall"
	"time"

	"golang.org/x/sys/unix"
)

// DefaultConfig is the configuration of a RAYXO server.
func DefaultConfig() Config {
	return Config{
		Proc:     "/proc",
		Passwd:   "/etc/passwd",
		PageSize: int64(os.Getpagesize()),
		Interval: 500 * time.Millisecond,
		Sleep:    time.Sleep,
		Open:     openPidfd,
		Self:     os.Getpid(),
	}
}

// pidfd is a process opened with pidfd_open: a signal sent through it only
// reaches that process, never one that got its PID later.
type pidfd int

func openPidfd(pid int) (Handle, error) {
	fd, err := unix.PidfdOpen(pid, 0)
	if err != nil {
		return nil, err
	}

	return pidfd(fd), nil
}

func (fd pidfd) Signal(sig syscall.Signal) error {
	return unix.PidfdSendSignal(int(fd), sig, nil, 0)
}

func (fd pidfd) Close() error {
	return unix.Close(int(fd))
}
