//go:build !linux

package procmon

import (
	"errors"
	"os"
	"time"
)

// DefaultConfig reads a /proc this platform does not have: listing finds
// nothing and stopping a process is not supported.
func DefaultConfig() Config {
	return Config{
		Proc:     "/proc",
		Passwd:   "/etc/passwd",
		PageSize: int64(os.Getpagesize()),
		Interval: 500 * time.Millisecond,
		Sleep:    time.Sleep,
		Open:     func(int) (Handle, error) { return nil, errors.ErrUnsupported },
		Self:     os.Getpid(),
	}
}
