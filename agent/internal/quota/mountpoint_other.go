//go:build !unix

package quota

import (
	"errors"
	"fmt"
	"runtime"
)

// mountPoint is not supported on this platform.
func mountPoint(string) (string, error) {
	return "", fmt.Errorf("finding mount points on %s: %w", runtime.GOOS, errors.ErrUnsupported)
}
