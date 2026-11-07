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
	fd, _, err := openDir(parentFD, dir, uid, gid)
	if err != nil {
		return err
	}

	return unix.Close(fd)
}

// openDir creates dir inside the directory open as parentFD when it is
// missing, sets its owner and mode, and returns it open. created reports
// whether this call made it.
func openDir(parentFD int, dir Dir, uid, gid int) (fd int, created bool, err error) {
	if dir.Name == "" || dir.Name == "." || dir.Name == ".." || strings.Contains(dir.Name, "/") {
		return -1, false, fmt.Errorf("invalid directory name %q", dir.Name)
	}

	err = unix.Mkdirat(parentFD, dir.Name, uint32(dir.Mode.Perm()))
	if err != nil && err != unix.EEXIST {
		return -1, false, fmt.Errorf("create: %w", err)
	}

	created = err == nil

	fd, err = unix.Openat(parentFD, dir.Name, openDirFlags, 0)
	if err != nil {
		return -1, false, fmt.Errorf("open: %w", inTheWay(err))
	}

	if err := setOwnerAndMode(fd, uid, gid, dir.Mode); err != nil {
		unix.Close(fd)

		return -1, false, err
	}

	return fd, created, nil
}

// prepareSite creates the nested directories of site inside its home and,
// when the last one was just created, its default index.html. Like
// prepareHome, it never follows a symlink: the account owner controls
// everything inside the home and may rename or replace any part of the path
// while this runs as root.
func prepareSite(site Site) error {
	if !filepath.IsAbs(site.Home) || filepath.Clean(site.Home) != site.Home {
		return fmt.Errorf("home %q must be a clean absolute path", site.Home)
	}

	if len(site.Dirs) == 0 {
		return fmt.Errorf("site in %s has no directories", site.Home)
	}

	fd, err := unix.Open(site.Home, openDirFlags, 0)
	if err != nil {
		return fmt.Errorf("open %s: %w", site.Home, inTheWay(err))
	}

	path, created := site.Home, false

	for _, dir := range site.Dirs {
		path += "/" + dir.Name

		child, made, err := openDir(fd, dir, site.UID, site.GID)
		unix.Close(fd)

		if err != nil {
			return fmt.Errorf("prepare %s: %w", path, err)
		}

		fd, created = child, made
	}
	defer unix.Close(fd)

	if !created || len(site.Index) == 0 {
		return nil
	}

	if err := writeNewFile(fd, "index.html", site.Index, site.UID, site.GID, 0o644); err != nil {
		return fmt.Errorf("write %s/index.html: %w", path, err)
	}

	return nil
}

// writeNewFile creates name inside the directory open as dirFD with data,
// owner and mode. A file or symlink already there is left alone: O_EXCL
// fails on both, so nothing outside the directory can be written through it.
func writeNewFile(dirFD int, name string, data []byte, uid, gid int, mode fs.FileMode) error {
	fd, err := unix.Openat(dirFD, name, unix.O_WRONLY|unix.O_CREAT|unix.O_EXCL|unix.O_NOFOLLOW|unix.O_CLOEXEC, uint32(mode.Perm()))
	if err == unix.EEXIST {
		return nil
	}

	if err != nil {
		return fmt.Errorf("create: %w", err)
	}

	err = writeAll(fd, data)
	if err == nil {
		err = setOwnerAndMode(fd, uid, gid, mode)
	}

	if closeErr := unix.Close(fd); err == nil {
		err = closeErr
	}

	if err != nil {
		_ = unix.Unlinkat(dirFD, name, 0)
	}

	return err
}

// writeAll writes all of data to fd.
func writeAll(fd int, data []byte) error {
	for len(data) > 0 {
		written, err := unix.Write(fd, data)
		if err == unix.EINTR {
			continue
		}

		if err != nil {
			return fmt.Errorf("write: %w", err)
		}

		data = data[written:]
	}

	return nil
}

// inTheWay marks the errors opening a directory without following symlinks
// gives when a symlink (ELOOP) or a non-directory (ENOTDIR) is in its place.
func inTheWay(err error) error {
	if err == unix.ELOOP || err == unix.ENOTDIR {
		return fmt.Errorf("%w: %w", ErrInTheWay, err)
	}

	return err
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
