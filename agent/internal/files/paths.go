package files

import (
	"path"
	"strings"
	"unicode/utf8"
)

const (
	// MaxPathBytes and MaxNameBytes are Linux's limits for a path and for
	// one name in it.
	MaxPathBytes = 4096
	MaxNameBytes = 255
	// TrashDir is the folder of the home that holds deleted files.
	TrashDir = ".trash"
)

// CheckPath checks p, a path relative to the home in the form the panel
// sends ("" is the home itself, folders separated by single slashes, no
// "." or ".." names). It refuses rather than cleans: a path that needs
// cleaning did not come from the file manager.
func CheckPath(p string) error {
	if p == "" {
		return nil
	}

	if len(p) > MaxPathBytes {
		return errorf(ReasonInvalid, "a path may have at most %d bytes", MaxPathBytes)
	}

	if strings.HasPrefix(p, "/") || strings.HasSuffix(p, "/") {
		return errorf(ReasonInvalid, "path %q must be relative to the home folder, without a leading or trailing slash", p)
	}

	for name := range strings.SplitSeq(p, "/") {
		if err := checkName(name, p); err != nil {
			return err
		}
	}

	return nil
}

// CheckName checks name, the new name of a file or folder.
func CheckName(name string) error {
	return checkName(name, name)
}

func checkName(name, whole string) error {
	switch {
	case name == "":
		return errorf(ReasonInvalid, "path %q has an empty name", whole)
	case name == "." || name == "..":
		return errorf(ReasonInvalid, "path %q may not contain . or ..", whole)
	case len(name) > MaxNameBytes:
		return errorf(ReasonInvalid, "a name may have at most %d bytes", MaxNameBytes)
	case !utf8.ValidString(name):
		return errorf(ReasonInvalid, "a name must be valid UTF-8")
	case strings.ContainsAny(name, "/\x00"):
		return errorf(ReasonInvalid, "a name may not contain a slash")
	}

	for _, r := range name {
		if r < 0x20 || r == 0x7f {
			return errorf(ReasonInvalid, "a name may not contain control characters")
		}
	}

	return nil
}

// inTrash reports whether p is the trash or inside it.
func inTrash(p string) bool {
	return p == TrashDir || strings.HasPrefix(p, TrashDir+"/")
}

// join returns the path of name in folder dir ("" is the home).
func join(dir, name string) string {
	if dir == "" {
		return name
	}

	return dir + "/" + name
}

// parent returns the folder p is in ("" for the home).
func parent(p string) string {
	dir := path.Dir(p)
	if dir == "." {
		return ""
	}

	return dir
}

// rootName is how os.Root names a path relative to the home: "." for the
// home itself.
func rootName(p string) string {
	if p == "" {
		return "."
	}

	return p
}

// within reports whether p is folder or inside it.
func within(p, folder string) bool {
	return folder == "" || p == folder || strings.HasPrefix(p, folder+"/")
}
