//go:build unix

package sysuser

import (
	"fmt"
	"io/fs"
	"path/filepath"
	"strings"

	"golang.org/x/sys/unix"
)

// openDirFlags open a directory without following a symlink in the last
// path component and without blocking on a FIFO put in its place.
const openDirFlags = unix.O_RDONLY | unix.O_DIRECTORY | unix.O_NOFOLLOW | unix.O_NONBLOCK | unix.O_CLOEXEC

// prepareHome creates home.Path and its directories when missing and sets
// their owners and modes. The account owner can rename and replace anything
// inside the home while this runs as root, so every change is made through
// a directory handle opened without following symlinks: a symlink or file in
// place of a directory is an error, never a way to chown another file.
func prepareHome(home Home) error {
	if !filepath.IsAbs(home.Path) || filepath.Clean(home.Path) != home.Path {
		return fmt.Errorf("home %q must be a clean absolute path", home.Path)
	}

	if err := unix.Mkdir(home.Path, uint32(home.Mode.Perm())); err != nil && err != unix.EEXIST {
		return fmt.Errorf("create %s: %w", home.Path, err)
	}

	homeFD, err := unix.Open(home.Path, openDirFlags, 0)
	if err != nil {
		return fmt.Errorf("open %s: %w", home.Path, err)
	}
	defer unix.Close(homeFD)

	if err := setOwnerAndMode(homeFD, home.UID, home.AccessGID, home.Mode); err != nil {
		return fmt.Errorf("prepare %s: %w", home.Path, err)
	}

	for _, dir := range home.Dirs {
		if err := prepareDir(homeFD, dir, home.UID, home.GID); err != nil {
			return fmt.Errorf("prepare %s/%s: %w", home.Path, dir.Name, err)
		}
	}

	return nil
}

// prepareDir creates dir inside the directory open as parentFD when it is
// missing, then sets its owner and mode.
func prepareDir(parentFD int, dir Dir, uid, gid int) error {
	if dir.Name == "" || dir.Name == "." || dir.Name == ".." || strings.Contains(dir.Name, "/") {
		return fmt.Errorf("invalid directory name %q", dir.Name)
	}

	if err := unix.Mkdirat(parentFD, dir.Name, uint32(dir.Mode.Perm())); err != nil && err != unix.EEXIST {
		return fmt.Errorf("create: %w", err)
	}

	fd, err := unix.Openat(parentFD, dir.Name, openDirFlags, 0)
	if err != nil {
		return fmt.Errorf("open (a symlink or file may be in the way): %w", err)
	}
	defer unix.Close(fd)

	return setOwnerAndMode(fd, uid, gid, dir.Mode)
}

// setOwnerAndMode changes the open file fd. The owner is set first, because
// changing it may clear mode bits.
func setOwnerAndMode(fd, uid, gid int, mode fs.FileMode) error {
	if err := unix.Fchown(fd, uid, gid); err != nil {
		return fmt.Errorf("chown: %w", err)
	}

	if err := unix.Fchmod(fd, uint32(mode.Perm())); err != nil {
		return fmt.Errorf("chmod: %w", err)
	}

	return nil
}
