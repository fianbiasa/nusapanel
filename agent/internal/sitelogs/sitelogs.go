// Package sitelogs reads the logs of the accounts' websites for the panel:
// the end of a log, a download of every log kept, and a GoAccess report of
// the visits. Nginx writes <domain>.access.log and <domain>.error.log to
// LogDir, Apache <domain>.apache-error.log; logrotate keeps 14 days of
// them (.1, then .2.gz and up).
//
// A log is only read for the account whose Nginx vhost the domain has, so
// one account never reads the visitors of another's website.
package sitelogs

import (
	"bytes"
	"compress/gzip"
	"context"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"syscall"
	"time"

	"rayxo/agent/internal/validate"
)

// Limits of what one call reads.
const (
	// MaxTailLines is the most lines a tail returns.
	MaxTailLines = 1000
	// MaxFilterLength is the longest text a tail filters on.
	MaxFilterLength = 200
	// tailWindow is how much of the end of a log a tail searches.
	tailWindow = 4 << 20
	// maxLineBytes cuts longer lines, which a visitor can send.
	maxLineBytes = 4096
	// maxRotations is the most rotated logs of one kind that are read.
	maxRotations = 30
	// MaxReportInput is how much log a report reads, newest first.
	MaxReportInput = 512 << 20
)

// Kinds are the logs a website has, by the name the panel uses.
var Kinds = map[string]string{
	"access":       "access.log",
	"error":        "error.log",
	"apache-error": "apache-error.log",
}

// ErrNoWebsite is returned for a domain the account has no website for.
var ErrNoWebsite = errors.New("the account has no website for this domain")

// ErrNoVisits is returned for a report when no access log has anything.
var ErrNoVisits = errors.New("the website has no visits in its logs yet")

// Config holds the locations the Manager reads and how it runs GoAccess.
type Config struct {
	// LogDir holds the websites' logs.
	LogDir string
	// NginxDir holds the vhosts, <NginxDir>/<user>/<domain>.conf.
	NginxDir string
	// GoAccess writes the HTML report of the access log lines read from
	// input to report.
	GoAccess func(ctx context.Context, input io.Reader, title string, report io.Writer) error
}

// Manager reads the websites' logs.
type Manager struct {
	cfg Config
}

// New returns a Manager for cfg.
func New(cfg Config) *Manager {
	return &Manager{cfg: cfg}
}

// Tail is the end of a log.
type Tail struct {
	Lines []string `json:"lines"`
	// Size is the size of the log in bytes; 0 when it does not exist yet.
	Size       int64      `json:"size"`
	ModifiedAt *time.Time `json:"modified_at"`
	// Partial is true when only the end of a large log was searched.
	Partial bool `json:"partial"`
}

// Tail returns the last lines of a log of the website, oldest first; with
// a filter, the last lines that contain it, in any case.
func (m *Manager) Tail(user, domain, kind string, lines int, filter string) (Tail, error) {
	path, err := m.logPath(user, domain, kind)
	if err != nil {
		return Tail{}, err
	}

	if lines < 1 || lines > MaxTailLines {
		return Tail{}, fmt.Errorf("lines must be between 1 and %d", MaxTailLines)
	}

	if len(filter) > MaxFilterLength {
		return Tail{}, fmt.Errorf("the filter may have at most %d characters", MaxFilterLength)
	}

	file, info, err := openLog(path)
	if errors.Is(err, fs.ErrNotExist) {
		return Tail{Lines: []string{}}, nil
	}

	if err != nil {
		return Tail{}, err
	}
	defer file.Close()

	modified := info.ModTime().UTC()
	result := Tail{Lines: []string{}, Size: info.Size(), ModifiedAt: &modified}

	start := max(0, info.Size()-tailWindow)
	chunk := make([]byte, info.Size()-start)

	if _, err := file.ReadAt(chunk, start); err != nil && !errors.Is(err, io.EOF) {
		return Tail{}, fmt.Errorf("read %s: %w", path, err)
	}

	if start > 0 {
		result.Partial = true

		// The first line is cut off.
		if newline := bytes.IndexByte(chunk, '\n'); newline >= 0 {
			chunk = chunk[newline+1:]
		}
	}

	all := strings.Split(strings.TrimRight(string(chunk), "\n"), "\n")
	needle := strings.ToLower(filter)

	for i := len(all) - 1; i >= 0 && len(result.Lines) < lines; i-- {
		line := all[i]

		if line == "" || (needle != "" && !strings.Contains(strings.ToLower(line), needle)) {
			continue
		}

		result.Lines = append(result.Lines, clean(line))
	}

	slices.Reverse(result.Lines)

	return result, nil
}

// Download writes every kept log of the kind, oldest first, as one gzip
// stream to w.
func (m *Manager) Download(user, domain, kind string, w io.Writer) error {
	path, err := m.logPath(user, domain, kind)
	if err != nil {
		return err
	}

	compressed := gzip.NewWriter(w)

	if _, err := copyLogs(logFiles(path), compressed, 0); err != nil {
		return err
	}

	return compressed.Close()
}

// Report writes the GoAccess report of the website's visits to w, made
// from every kept access log, oldest first, up to MaxReportInput.
func (m *Manager) Report(ctx context.Context, user, domain string, w io.Writer) error {
	path, err := m.logPath(user, domain, "access")
	if err != nil {
		return err
	}

	files := logFiles(path)

	var size int64

	for _, file := range files {
		if info, err := os.Lstat(file); err == nil && info.Mode().IsRegular() {
			size += info.Size()
		}
	}

	if size == 0 {
		return ErrNoVisits
	}

	// The newest logs count most: whatever is over the limit is left out
	// from the oldest end.
	skip := max(0, size-MaxReportInput)
	reader, writer := io.Pipe()

	go func() {
		_, err := copyLogs(files, writer, skip)
		writer.CloseWithError(err)
	}()

	// Unblocks the copy when GoAccess stops reading early.
	defer reader.Close()

	return m.cfg.GoAccess(ctx, reader, domain, w)
}

// logPath is the current log of the kind of domain, after checking that
// the account has a website for it.
func (m *Manager) logPath(user, domain, kind string) (string, error) {
	if err := validate.Username(user); err != nil {
		return "", err
	}

	if err := validate.Domain(domain); err != nil {
		return "", err
	}

	suffix, ok := Kinds[kind]
	if !ok {
		return "", fmt.Errorf("unknown log %q", kind)
	}

	info, err := os.Lstat(filepath.Join(m.cfg.NginxDir, user, domain+".conf"))
	if errors.Is(err, fs.ErrNotExist) || (err == nil && !info.Mode().IsRegular()) {
		return "", fmt.Errorf("%w: %s", ErrNoWebsite, domain)
	}

	if err != nil {
		return "", fmt.Errorf("check the website %s: %w", domain, err)
	}

	return filepath.Join(m.cfg.LogDir, domain+"."+suffix), nil
}

// copyLogs writes files to w in order, decompressing rotated ones, after
// leaving out whole files that fall in the first skip bytes (counted as
// they are on disk).
func copyLogs(files []string, w io.Writer, skip int64) (int64, error) {
	var written int64

	for _, path := range files {
		file, info, err := openLog(path)
		if errors.Is(err, fs.ErrNotExist) {
			continue
		}

		if err != nil {
			return written, err
		}

		if skip >= info.Size() {
			skip -= info.Size()
			file.Close()

			continue
		}

		skip = 0

		var reader io.Reader = file

		if strings.HasSuffix(path, ".gz") {
			unzipped, err := gzip.NewReader(file)
			if err != nil {
				file.Close()

				return written, fmt.Errorf("read %s: %w", path, err)
			}

			reader = unzipped
		}

		n, err := io.Copy(w, reader)
		written += n

		file.Close()

		if err != nil {
			return written, fmt.Errorf("copy %s: %w", path, err)
		}
	}

	return written, nil
}

// logFiles are the log at path and its rotations, oldest first: .30.gz
// down to .2.gz, .1, then the log itself.
func logFiles(path string) []string {
	var files []string

	for n := maxRotations; n >= 1; n-- {
		for _, name := range []string{path + "." + strconv.Itoa(n) + ".gz", path + "." + strconv.Itoa(n)} {
			if _, err := os.Lstat(name); err == nil {
				files = append(files, name)

				break
			}
		}
	}

	return append(files, path)
}

// openLog opens a log for reading, refusing links and anything that is
// not a regular file.
func openLog(path string) (*os.File, fs.FileInfo, error) {
	file, err := os.OpenFile(path, os.O_RDONLY|syscall.O_NOFOLLOW, 0)
	if err != nil {
		return nil, nil, err
	}

	info, err := file.Stat()
	if err != nil {
		file.Close()

		return nil, nil, err
	}

	if !info.Mode().IsRegular() {
		file.Close()

		return nil, nil, fmt.Errorf("%s is not a regular file", path)
	}

	return file, info, nil
}

// clean makes a log line safe to send as JSON text: valid UTF-8 and at
// most maxLineBytes long.
func clean(line string) string {
	line = strings.ToValidUTF8(line, "�")

	if len(line) <= maxLineBytes {
		return line
	}

	cut := maxLineBytes
	for cut > 0 && line[cut]&0xC0 == 0x80 {
		cut--
	}

	return line[:cut] + "…"
}
