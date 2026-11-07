//go:build linux || darwin

package sysinfo

import (
	"fmt"

	"golang.org/x/sys/unix"
)

// diskUsage reports the size and used space of the filesystem at mount,
// counting used space the way df does (total minus free blocks).
func diskUsage(mount string) (Disk, error) {
	var stat unix.Statfs_t
	if err := unix.Statfs(mount, &stat); err != nil {
		return Disk{}, fmt.Errorf("read disk usage of %s: %w", mount, err)
	}

	blockSize := uint64(stat.Bsize)
	total := stat.Blocks * blockSize
	free := stat.Bfree * blockSize

	return Disk{
		Mount:   mount,
		TotalGB: roundGiB(total),
		UsedGB:  roundGiB(total - free),
	}, nil
}
