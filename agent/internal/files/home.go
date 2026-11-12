package files

import (
	"bytes"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path"
	"slices"
	"strings"
	"syscall"
	"time"
	"unicode/utf8"
)

const (
	// MaxEntries limits one folder listing.
	MaxEntries = 5000
	// MaxEditBytes is the largest file the editor opens and saves.
	MaxEditBytes = 2 << 20
	// MaxItems limits the paths one operation acts on.
	MaxItems = 1000

	newFileMode fs.FileMode = 0o644
	newDirMode  fs.FileMode = 0o755
)

// Types of entries.
const (
	TypeFile      = "file"
	TypeDirectory = "directory"
	TypeLink      = "link"
	TypeOther     = "other"
	// TypeMissing and TypeOutside are what a link points to when its
	// target does not exist or is outside the home.
	TypeMissing = "missing"
	TypeOutside = "outside"
)

// Home is an account's home folder. Every path is relative to it, and
// os.Root keeps every operation inside it, links included.
type Home struct {
	root *os.Root
	now  func() time.Time
}

// OpenHome opens the home folder dir.
func OpenHome(dir string) (*Home, error) {
	root, err := os.OpenRoot(dir)
	if err != nil {
		return nil, err
	}

	return &Home{root: root, now: time.Now}, nil
}

// Close releases the home folder.
func (h *Home) Close() error {
	return h.root.Close()
}

// Entry is one file, folder or link of a listing.
type Entry struct {
	Name string `json:"name"`
	Type string `json:"type"`
	// Size is in bytes; 0 for folders.
	Size int64 `json:"size"`
	// Mode holds the permission bits in octal, such as "0644".
	Mode string `json:"mode"`
	// Modified is the time of the last change, in Unix seconds.
	Modified int64 `json:"modified"`
	// Target and TargetType describe what a link points to.
	Target     string `json:"target,omitempty"`
	TargetType string `json:"target_type,omitempty"`
}

// Listing is the content of a folder: folders first, then by name.
type Listing struct {
	Path      string  `json:"path"`
	Entries   []Entry `json:"entries"`
	Truncated bool    `json:"truncated"`
}

// List returns the content of folder p.
func (h *Home) List(p string) (Listing, error) {
	dir, err := h.openForRead(rootName(p))
	if err != nil {
		return Listing{}, fail(err, p)
	}
	defer dir.Close()

	info, err := dir.Stat()
	if err != nil {
		return Listing{}, fail(err, p)
	}

	if !info.IsDir() {
		return Listing{}, errorf(ReasonNotDirectory, "%s is not a folder", display(p))
	}

	dirEntries, err := dir.ReadDir(-1)
	if err != nil {
		return Listing{}, fail(err, p)
	}

	entries := make([]Entry, 0, len(dirEntries))

	for _, dirEntry := range dirEntries {
		info, err := dirEntry.Info()
		if err != nil {
			// Removed while we were reading the folder.
			continue
		}

		entries = append(entries, h.entry(join(p, dirEntry.Name()), info))
	}

	slices.SortFunc(entries, compareEntries)

	listing := Listing{Path: p, Entries: entries}
	if len(entries) > MaxEntries {
		listing.Entries = entries[:MaxEntries]
		listing.Truncated = true
	}

	return listing, nil
}

// entry describes p, whose own information (not followed) is info.
func (h *Home) entry(p string, info fs.FileInfo) Entry {
	entry := Entry{
		Name:     info.Name(),
		Type:     typeOf(info.Mode()),
		Size:     info.Size(),
		Mode:     fmt.Sprintf("%04o", info.Mode().Perm()),
		Modified: info.ModTime().Unix(),
	}

	if entry.Type == TypeDirectory {
		entry.Size = 0
	}

	if entry.Type == TypeLink {
		if target, err := h.root.Readlink(p); err == nil {
			entry.Target = target
		}

		target, err := h.root.Stat(p)

		switch {
		case err == nil:
			entry.TargetType = typeOf(target.Mode())
		case strings.Contains(err.Error(), "path escapes"):
			entry.TargetType = TypeOutside
		default:
			entry.TargetType = TypeMissing
		}
	}

	return entry
}

func typeOf(mode fs.FileMode) string {
	switch {
	case mode&fs.ModeSymlink != 0:
		return TypeLink
	case mode.IsDir():
		return TypeDirectory
	case mode.IsRegular():
		return TypeFile
	}

	return TypeOther
}

// compareEntries puts folders, and links to folders, before everything
// else, then sorts by name regardless of case.
func compareEntries(a, b Entry) int {
	aFolder := a.Type == TypeDirectory || a.TargetType == TypeDirectory
	bFolder := b.Type == TypeDirectory || b.TargetType == TypeDirectory

	if aFolder != bFolder {
		if aFolder {
			return -1
		}

		return 1
	}

	if order := strings.Compare(strings.ToLower(a.Name), strings.ToLower(b.Name)); order != 0 {
		return order
	}

	return strings.Compare(a.Name, b.Name)
}

// Content is a text file opened in the editor. Version changes whenever
// the file does, so a save can tell whether someone changed it meanwhile.
type Content struct {
	Content string `json:"content"`
	Size    int64  `json:"size"`
	Version string `json:"version"`
}

// Read returns the content of text file p.
func (h *Home) Read(p string) (Content, error) {
	if err := h.refuseLink(p); err != nil {
		return Content{}, err
	}

	file, err := h.openForRead(rootName(p))
	if err != nil {
		return Content{}, fail(err, p)
	}
	defer file.Close()

	info, err := file.Stat()
	if err != nil {
		return Content{}, fail(err, p)
	}

	if err := regularFile(info, p); err != nil {
		return Content{}, err
	}

	if info.Size() > MaxEditBytes {
		return Content{}, errorf(ReasonTooLarge, "%s is larger than %d MB", p, MaxEditBytes>>20)
	}

	data, err := io.ReadAll(io.LimitReader(file, MaxEditBytes+1))
	if err != nil {
		return Content{}, fail(err, p)
	}

	if len(data) > MaxEditBytes {
		return Content{}, errorf(ReasonTooLarge, "%s is larger than %d MB", p, MaxEditBytes>>20)
	}

	if !utf8.Valid(data) || bytes.IndexByte(data, 0) >= 0 {
		return Content{}, errorf(ReasonNotText, "%s is not a text file", p)
	}

	return Content{Content: string(data), Size: int64(len(data)), Version: version(info)}, nil
}

// Write replaces the content of file p, or creates it. version is what Read
// returned; when the file changed since, nothing is written. The file keeps
// its permissions.
func (h *Home) Write(p, content, expected string) (Content, error) {
	if err := h.checkSource(p); err != nil {
		return Content{}, err
	}

	if len(content) > MaxEditBytes {
		return Content{}, errorf(ReasonTooLarge, "a file saved from the editor may have at most %d MB", MaxEditBytes>>20)
	}

	mode := newFileMode
	info, err := h.root.Lstat(p)

	switch {
	case errors.Is(err, fs.ErrNotExist):
		if expected != "" {
			return Content{}, errorf(ReasonChanged, "%s was deleted after it was opened", p)
		}
	case err != nil:
		return Content{}, fail(err, p)
	case info.Mode()&fs.ModeSymlink != 0:
		return Content{}, linkError(p)
	default:
		if err := regularFile(info, p); err != nil {
			return Content{}, err
		}

		if expected != "" && version(info) != expected {
			return Content{}, errorf(ReasonChanged, "%s was changed after it was opened", p)
		}

		mode = info.Mode().Perm()
	}

	if err := h.replace(p, mode, strings.NewReader(content)); err != nil {
		return Content{}, err
	}

	info, err = h.root.Lstat(p)
	if err != nil {
		return Content{}, fail(err, p)
	}

	return Content{Size: info.Size(), Version: version(info)}, nil
}

// CreateFile creates the empty file p.
func (h *Home) CreateFile(p string) error {
	if err := h.checkSource(p); err != nil {
		return err
	}

	file, err := h.root.OpenFile(p, os.O_WRONLY|os.O_CREATE|os.O_EXCL, newFileMode)
	if err != nil {
		return fail(err, p)
	}

	return fail(file.Close(), p)
}

// Mkdir creates the folder p.
func (h *Home) Mkdir(p string) error {
	if err := h.checkSource(p); err != nil {
		return err
	}

	return fail(h.root.Mkdir(p, newDirMode), p)
}

// Rename gives p the new name, in the same folder.
func (h *Home) Rename(p, name string) error {
	if err := h.checkSource(p); err != nil {
		return err
	}

	target := join(parent(p), name)
	if target == p {
		return nil
	}

	if _, err := h.root.Lstat(p); err != nil {
		return fail(err, p)
	}

	if err := h.free(target); err != nil {
		return err
	}

	return fail(h.root.Rename(p, target), p)
}

// Failure is one path an operation could not act on.
type Failure struct {
	Path    string `json:"path"`
	Reason  string `json:"reason"`
	Message string `json:"message"`
}

// Outcome is what an operation on several paths did: the paths it acted
// on, and those it could not.
type Outcome struct {
	Done   []string  `json:"done"`
	Failed []Failure `json:"failed"`
}

func (o *Outcome) add(p string, err error) {
	if err == nil {
		o.Done = append(o.Done, p)

		return
	}

	var failure *Error
	if !errors.As(fail(err, p), &failure) {
		failure = errorf(ReasonFailed, "%s could not be changed", p)
	}

	o.Failed = append(o.Failed, Failure{Path: p, Reason: failure.Reason, Message: failure.Message})
}

func newOutcome() Outcome {
	return Outcome{Done: []string{}, Failed: []Failure{}}
}

// Move moves every path into folder destination, keeping their names.
func (h *Home) Move(paths []string, destination string) Outcome {
	return h.transfer(paths, destination, func(p, target string) error {
		return fail(h.root.Rename(p, target), p)
	})
}

// Copy copies every path, folders with all they hold, into folder
// destination, keeping their names. Links are copied as links.
func (h *Home) Copy(paths []string, destination string) Outcome {
	return h.transfer(paths, destination, h.copyTree)
}

func (h *Home) transfer(paths []string, destination string, act func(p, target string) error) Outcome {
	outcome := newOutcome()
	destinationErr := h.checkFolder(destination)

	if destinationErr == nil && inTrash(destination) {
		destinationErr = trashError()
	}

	for _, p := range paths {
		outcome.add(p, h.transferOne(p, destination, destinationErr, act))
	}

	return outcome
}

func (h *Home) transferOne(p, destination string, destinationErr error, act func(p, target string) error) error {
	if destinationErr != nil {
		return destinationErr
	}

	if err := h.checkSource(p); err != nil {
		return err
	}

	if _, err := h.root.Lstat(p); err != nil {
		return fail(err, p)
	}

	target := join(destination, path.Base(p))

	if target == p {
		return errorf(ReasonExists, "%s is already in %s", p, display(destination))
	}

	if within(destination, p) {
		return errorf(ReasonIntoItself, "%s cannot go into itself", p)
	}

	if err := h.free(target); err != nil {
		return err
	}

	return act(p, target)
}

// copyTree copies src to dst, which does not exist yet.
func (h *Home) copyTree(src, dst string) error {
	info, err := h.root.Lstat(src)
	if err != nil {
		return fail(err, src)
	}

	switch {
	case info.Mode()&fs.ModeSymlink != 0:
		target, err := h.root.Readlink(src)
		if err != nil {
			return fail(err, src)
		}

		return fail(h.root.Symlink(target, dst), dst)

	case info.IsDir():
		// Writable while it is filled; its own mode comes last.
		if err := h.root.Mkdir(dst, 0o700); err != nil {
			return fail(err, dst)
		}

		names, err := h.names(src)
		if err != nil {
			return err
		}

		for _, name := range names {
			if err := h.copyTree(join(src, name), join(dst, name)); err != nil {
				return err
			}
		}

		return fail(h.root.Chmod(dst, info.Mode().Perm()), dst)

	case info.Mode().IsRegular():
		in, err := h.openRegular(src)
		if err != nil {
			return fail(err, src)
		}
		defer in.Close()

		out, err := h.root.OpenFile(dst, os.O_WRONLY|os.O_CREATE|os.O_EXCL, info.Mode().Perm())
		if err != nil {
			return fail(err, dst)
		}

		_, err = io.Copy(out, in)
		if closeErr := out.Close(); err == nil {
			err = closeErr
		}

		if err == nil {
			err = h.root.Chmod(dst, info.Mode().Perm())
		}

		return fail(err, dst)
	}

	// Sockets, pipes and devices are left out.
	return nil
}

// names returns the names in folder p.
func (h *Home) names(p string) ([]string, error) {
	dir, err := h.openForRead(rootName(p))
	if err != nil {
		return nil, fail(err, p)
	}
	defer dir.Close()

	names, err := dir.Readdirnames(-1)
	if err != nil {
		return nil, fail(err, p)
	}

	slices.Sort(names)

	return names, nil
}

// Chmod sets the permission bits of every path.
func (h *Home) Chmod(paths []string, mode fs.FileMode) Outcome {
	outcome := newOutcome()

	for _, p := range paths {
		outcome.add(p, h.chmod(p, mode))
	}

	return outcome
}

func (h *Home) chmod(p string, mode fs.FileMode) error {
	if err := h.checkSource(p); err != nil {
		return err
	}

	if err := h.refuseLink(p); err != nil {
		return err
	}

	return fail(h.root.Chmod(p, mode.Perm()), p)
}

// Upload stores what r holds as file p. An existing file is only replaced
// when overwrite is set, and keeps its permissions.
func (h *Home) Upload(r io.Reader, p string, overwrite bool) (Entry, error) {
	if err := h.checkSource(p); err != nil {
		return Entry{}, err
	}

	if err := h.checkFolder(parent(p)); err != nil {
		return Entry{}, err
	}

	mode := newFileMode
	info, err := h.root.Lstat(p)

	switch {
	case errors.Is(err, fs.ErrNotExist):
	case err != nil:
		return Entry{}, fail(err, p)
	case !overwrite:
		return Entry{}, errorf(ReasonExists, "%s already exists", p)
	case info.Mode()&fs.ModeSymlink != 0:
		return Entry{}, linkError(p)
	default:
		if err := regularFile(info, p); err != nil {
			return Entry{}, err
		}

		mode = info.Mode().Perm()
	}

	if err := h.replace(p, mode, r); err != nil {
		return Entry{}, err
	}

	info, err = h.root.Lstat(p)
	if err != nil {
		return Entry{}, fail(err, p)
	}

	return h.entry(p, info), nil
}

// Download writes the content of file p to w.
func (h *Home) Download(w io.Writer, p string) (Entry, error) {
	file, err := h.openForRead(rootName(p))
	if err != nil {
		return Entry{}, fail(err, p)
	}
	defer file.Close()

	info, err := file.Stat()
	if err != nil {
		return Entry{}, fail(err, p)
	}

	if err := regularFile(info, p); err != nil {
		return Entry{}, err
	}

	if _, err := io.Copy(w, file); err != nil {
		return Entry{}, fail(err, p)
	}

	return h.entry(p, info), nil
}

// replace writes r to a new file next to p and renames it over p, so p is
// never half written.
func (h *Home) replace(p string, mode fs.FileMode, r io.Reader) error {
	temp := join(parent(p), ".rayxo-"+randomHex(8)+".tmp")

	file, err := h.root.OpenFile(temp, os.O_WRONLY|os.O_CREATE|os.O_EXCL, mode)
	if err != nil {
		return fail(err, p)
	}

	_, err = io.Copy(file, r)
	if err == nil {
		err = file.Sync()
	}

	if closeErr := file.Close(); err == nil {
		err = closeErr
	}

	if err == nil {
		err = h.root.Chmod(temp, mode)
	}

	if err == nil {
		err = h.root.Rename(temp, p)
	}

	if err != nil {
		_ = h.root.Remove(temp)

		return fail(err, p)
	}

	return nil
}

// checkSource refuses the home itself and the trash.
func (h *Home) checkSource(p string) error {
	if p == "" {
		return errorf(ReasonInvalid, "the home folder itself cannot be changed")
	}

	return h.refuseTrash(p)
}

func (h *Home) refuseTrash(p string) error {
	if inTrash(p) {
		return trashError()
	}

	return nil
}

func trashError() *Error {
	return errorf(ReasonTrash, "the trash can only be changed from the Trash page")
}

// refuseLink refuses p when it is a link.
func (h *Home) refuseLink(p string) error {
	info, err := h.root.Lstat(rootName(p))
	if err != nil {
		return fail(err, p)
	}

	if info.Mode()&fs.ModeSymlink != 0 {
		return linkError(p)
	}

	return nil
}

func linkError(p string) *Error {
	return errorf(ReasonLink, "%s is a link; open what it points to instead", p)
}

// checkFolder makes sure p is a folder (or a link to one in the home).
func (h *Home) checkFolder(p string) error {
	info, err := h.root.Stat(rootName(p))
	if err != nil {
		return fail(err, p)
	}

	if !info.IsDir() {
		return errorf(ReasonNotDirectory, "%s is not a folder", display(p))
	}

	return nil
}

// free makes sure nothing exists at p.
func (h *Home) free(p string) error {
	_, err := h.root.Lstat(p)

	switch {
	case err == nil:
		return errorf(ReasonExists, "%s already exists", p)
	case errors.Is(err, fs.ErrNotExist):
		return nil
	}

	return fail(err, p)
}

// openForRead opens name for reading without blocking: open(2) on a FIFO
// waits for a writer, so a FIFO a customer left in the home would hold the
// call, and one of the agent's call slots, until it times out. Callers
// still check what they opened before reading it.
func (h *Home) openForRead(name string) (*os.File, error) {
	return h.root.OpenFile(name, os.O_RDONLY|syscall.O_NONBLOCK, 0)
}

// openRegular opens regular file name for reading. Anything else is
// refused before a byte is read, also a file swapped for a FIFO or a device
// after its type was checked.
func (h *Home) openRegular(name string) (*os.File, error) {
	file, err := h.openForRead(name)
	if err != nil {
		return nil, err
	}

	info, err := file.Stat()
	if err == nil {
		err = regularFile(info, name)
	}

	if err != nil {
		file.Close()

		return nil, err
	}

	return file, nil
}

func regularFile(info fs.FileInfo, p string) error {
	switch {
	case info.IsDir():
		return errorf(ReasonIsDirectory, "%s is a folder", display(p))
	case !info.Mode().IsRegular():
		return errorf(ReasonInvalid, "%s is not a regular file", p)
	}

	return nil
}

// version identifies the state of a file: it changes when the file is
// written.
func version(info fs.FileInfo) string {
	return fmt.Sprintf("%d-%d", info.ModTime().UnixNano(), info.Size())
}

func randomHex(n int) string {
	buf := make([]byte, n)
	_, _ = rand.Read(buf)

	return hex.EncodeToString(buf)
}
