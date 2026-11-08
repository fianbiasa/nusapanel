//go:build unix

package quota

import (
	"fmt"
	"path/filepath"

	"golang.org/x/sys/unix"
)

// mountPoint returns the mount point of the filesystem that holds path:
// the highest directory on the way up from path that is still on the same
// device. quotaon, setquota and repquota need that mount point; a directory
// inside it is not enough.
func mountPoint(path string) (string, error) {
	dir, err := filepath.EvalSymlinks(path)
	if err != nil {
		return "", err
	}

	if dir, err = filepath.Abs(dir); err != nil {
		return "", err
	}

	var stat unix.Stat_t
	if err := unix.Stat(dir, &stat); err != nil {
		return "", fmt.Errorf("stat %s: %w", dir, err)
	}

	for dir != "/" {
		parent := filepath.Dir(dir)

		var parentStat unix.Stat_t
		if err := unix.Stat(parent, &parentStat); err != nil {
			return "", fmt.Errorf("stat %s: %w", parent, err)
		}

		if parentStat.Dev != stat.Dev {
			return dir, nil
		}

		dir = parent
	}

	return dir, nil
}
