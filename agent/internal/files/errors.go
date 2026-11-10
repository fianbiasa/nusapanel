// Package files is the agent's side of the file manager. Every operation
// runs in a helper process with the account's uid and gid (Runner), inside
// the account's home opened as an os.Root, so the operating system decides
// what the account may touch and no path or link can lead out of its home.
package files

import (
	"errors"
	"fmt"
	"io/fs"
	"os"
	"strings"
	"syscall"
)

// Reasons the panel can turn into its own message.
const (
	ReasonInvalid      = "invalid"
	ReasonNotFound     = "not_found"
	ReasonExists       = "exists"
	ReasonNotDirectory = "not_directory"
	ReasonIsDirectory  = "is_directory"
	ReasonPermission   = "permission_denied"
	ReasonOutside      = "outside_home"
	ReasonNoSpace      = "no_space"
	ReasonTooLarge     = "too_large"
	ReasonNotText      = "not_text"
	ReasonChanged      = "changed"
	ReasonLink         = "link"
	ReasonIntoItself   = "into_itself"
	ReasonTrash        = "trash"
	ReasonFailed       = "failed"
)

// Error is a failure the account can understand: what went wrong (Reason)
// and a message naming the path, relative to the home.
type Error struct {
	Reason  string `json:"reason"`
	Message string `json:"message"`
}

func (e *Error) Error() string {
	return e.Message
}

// errorf returns an *Error for reason.
func errorf(reason, format string, args ...any) *Error {
	return &Error{Reason: reason, Message: fmt.Sprintf(format, args...)}
}

// fail turns an error from the operating system about path into an *Error.
// Errors that already are one pass through.
func fail(err error, path string) error {
	if err == nil {
		return nil
	}

	var known *Error
	if errors.As(err, &known) {
		return known
	}

	shown := display(path)

	switch {
	case errors.Is(err, fs.ErrNotExist):
		return errorf(ReasonNotFound, "%s does not exist", shown)
	case errors.Is(err, fs.ErrExist), errors.Is(err, syscall.ENOTEMPTY):
		return errorf(ReasonExists, "%s already exists", shown)
	case errors.Is(err, syscall.ENOTDIR):
		return errorf(ReasonNotDirectory, "%s is not a folder", shown)
	case errors.Is(err, syscall.EISDIR):
		return errorf(ReasonIsDirectory, "%s is a folder", shown)
	case errors.Is(err, syscall.ENOSPC), errors.Is(err, syscall.EDQUOT):
		return errorf(ReasonNoSpace, "no space left for %s (disk quota)", shown)
	case errors.Is(err, fs.ErrPermission):
		return errorf(ReasonPermission, "no permission for %s", shown)
	case strings.Contains(err.Error(), "path escapes"):
		// os.Root refuses links that lead out of the home.
		return errorf(ReasonOutside, "%s leads outside the home folder", shown)
	}

	return errorf(ReasonFailed, "%s: %v", shown, underlying(err))
}

// underlying drops the absolute paths an *fs.PathError or *os.LinkError
// carries, so messages only name paths relative to the home.
func underlying(err error) error {
	var pathErr *fs.PathError
	if errors.As(err, &pathErr) {
		return pathErr.Err
	}

	var linkErr *os.LinkError
	if errors.As(err, &linkErr) {
		return linkErr.Err
	}

	return err
}

// display returns path as the account sees it.
func display(path string) string {
	if path == "" {
		return "the home folder"
	}

	return path
}
