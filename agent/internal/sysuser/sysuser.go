// Package sysuser manages local Linux users, groups and home directories for
// the agent. It only carries out operations; deciding which accounts may be
// touched is up to the caller. Outside Linux every operation fails with
// errors.ErrUnsupported, so nusad still runs on a Mac during development.
package sysuser

import (
	"errors"
	"fmt"
	"io/fs"
	"strconv"
	"strings"
)

var (
	// ErrNotFound means the user or group does not exist.
	ErrNotFound = errors.New("not found")
	// ErrNameTaken means a new user could not be added because a user or
	// group with that name already exists.
	ErrNameTaken = errors.New("user or group name already in use")
)

// User is a local account as the system reports it.
type User struct {
	Name    string
	UID     int
	GID     int
	Comment string // the GECOS field
	Home    string
	Shell   string
}

// Group is a local group and its supplementary members.
type Group struct {
	Name    string
	GID     int
	Members []string
}

// NewUser describes an account for AddUser. The user gets its own group of
// the same name as primary group, and its home directory is created.
type NewUser struct {
	Name    string
	Home    string
	Shell   string
	Comment string
}

// Home describes a home directory and the directories it must contain.
type Home struct {
	// Path is the clean absolute path of the home directory.
	Path string
	// Mode is the permission of Path, which is owned by UID:AccessGID.
	Mode      fs.FileMode
	UID       int
	AccessGID int
	// GID is the group of every directory in Dirs, which are owned by
	// UID:GID.
	GID  int
	Dirs []Dir
}

// Dir is a directory directly inside a home directory.
type Dir struct {
	Name string
	Mode fs.FileMode
}

// parsePasswd parses one passwd entry, as printed by `getent passwd`.
func parsePasswd(line string) (User, error) {
	fields := strings.Split(strings.TrimSuffix(line, "\n"), ":")
	if len(fields) != 7 {
		return User{}, fmt.Errorf("passwd entry %q does not have 7 fields", line)
	}

	uid, err := parseID(fields[2])
	if err != nil {
		return User{}, fmt.Errorf("passwd entry %q: uid: %w", line, err)
	}

	gid, err := parseID(fields[3])
	if err != nil {
		return User{}, fmt.Errorf("passwd entry %q: gid: %w", line, err)
	}

	return User{
		Name:    fields[0],
		UID:     uid,
		GID:     gid,
		Comment: fields[4],
		Home:    fields[5],
		Shell:   fields[6],
	}, nil
}

// parseGroup parses one group entry, as printed by `getent group`.
func parseGroup(line string) (Group, error) {
	fields := strings.Split(strings.TrimSuffix(line, "\n"), ":")
	if len(fields) != 4 {
		return Group{}, fmt.Errorf("group entry %q does not have 4 fields", line)
	}

	gid, err := parseID(fields[2])
	if err != nil {
		return Group{}, fmt.Errorf("group entry %q: gid: %w", line, err)
	}

	var members []string
	if fields[3] != "" {
		members = strings.Split(fields[3], ",")
	}

	return Group{Name: fields[0], GID: gid, Members: members}, nil
}

// parseID parses a uid or gid, which must fit in 32 bits.
func parseID(value string) (int, error) {
	id, err := strconv.ParseUint(value, 10, 32)
	if err != nil {
		return 0, err
	}

	return int(id), nil
}

// firstLine returns the first line of a program's output.
func firstLine(output []byte) string {
	line, _, _ := strings.Cut(string(output), "\n")

	return line
}
