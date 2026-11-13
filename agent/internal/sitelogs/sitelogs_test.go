package sitelogs

import (
	"bytes"
	"compress/gzip"
	"context"
	"errors"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// fixture is a server with the website example.com of budi.
type fixture struct {
	logDir string
	// input is what the fake GoAccess read.
	input string
}

func newFixture(t *testing.T) (*fixture, *Manager) {
	t.Helper()

	nginxDir, logDir := t.TempDir(), t.TempDir()
	must(t, os.MkdirAll(filepath.Join(nginxDir, "budi"), 0o755))
	must(t, os.WriteFile(filepath.Join(nginxDir, "budi", "example.com.conf"), []byte("server {}"), 0o644))

	f := &fixture{logDir: logDir}

	return f, New(Config{
		LogDir:   logDir,
		NginxDir: nginxDir,
		GoAccess: func(_ context.Context, input io.Reader, title string, report io.Writer) error {
			read, err := io.ReadAll(input)
			f.input = string(read)
			_, _ = io.WriteString(report, "<html>"+title+"</html>")

			return err
		},
	})
}

func (f *fixture) write(t *testing.T, name, content string) {
	t.Helper()

	data := []byte(content)

	if strings.HasSuffix(name, ".gz") {
		var buffer bytes.Buffer
		zipped := gzip.NewWriter(&buffer)
		_, _ = zipped.Write(data)
		must(t, zipped.Close())
		data = buffer.Bytes()
	}

	must(t, os.WriteFile(filepath.Join(f.logDir, name), data, 0o640))
}

func TestTailReturnsTheLastLinesOldestFirst(t *testing.T) {
	f, logs := newFixture(t)
	f.write(t, "example.com.access.log", "one GET /\ntwo POST /login\nthree GET /a\nfour POST /b\n")

	tail, err := logs.Tail("budi", "example.com", "access", 2, "")
	if err != nil {
		t.Fatal(err)
	}

	if strings.Join(tail.Lines, "|") != "three GET /a|four POST /b" || tail.Size == 0 || tail.ModifiedAt == nil || tail.Partial {
		t.Errorf("tail = %+v", tail)
	}

	tail, _ = logs.Tail("budi", "example.com", "access", 10, "post")
	if strings.Join(tail.Lines, "|") != "two POST /login|four POST /b" {
		t.Errorf("filtered tail = %q", tail.Lines)
	}
}

func TestTailOfALogNotWrittenYetIsEmpty(t *testing.T) {
	_, logs := newFixture(t)

	tail, err := logs.Tail("budi", "example.com", "apache-error", 100, "")
	if err != nil || len(tail.Lines) != 0 || tail.Lines == nil {
		t.Errorf("tail = %+v, %v", tail, err)
	}
}

func TestTailOfALargeLogReadsItsEnd(t *testing.T) {
	f, logs := newFixture(t)
	f.write(t, "example.com.error.log", strings.Repeat("old line\n", tailWindow/9+100)+"newest\n")

	tail, err := logs.Tail("budi", "example.com", "error", 1, "")
	if err != nil || !tail.Partial || tail.Lines[0] != "newest" {
		t.Errorf("tail = %v, partial %v, %v", tail.Lines, tail.Partial, err)
	}
}

func TestLinesFromVisitorsAreMadeSafe(t *testing.T) {
	f, logs := newFixture(t)
	f.write(t, "example.com.access.log", "bad \xff\xfe agent\n"+strings.Repeat("é", maxLineBytes)+"\n")

	tail, _ := logs.Tail("budi", "example.com", "access", 2, "")

	if tail.Lines[0] != "bad � agent" {
		t.Errorf("invalid UTF-8 became %q", tail.Lines[0])
	}

	if long := tail.Lines[1]; len(long) > maxLineBytes+len("…") || !strings.HasSuffix(long, "é…") {
		t.Errorf("a long line became %d bytes ending %q", len(long), long[len(long)-8:])
	}
}

func TestOnlyTheAccountsOwnWebsitesHaveLogs(t *testing.T) {
	f, logs := newFixture(t)
	f.write(t, "other.org.access.log", "secret visit\n")

	for name, call := range map[string]func() error{
		"another account's website": func() error { _, err := logs.Tail("sari", "example.com", "access", 10, ""); return err },
		"a domain without website":  func() error { _, err := logs.Tail("budi", "other.org", "access", 10, ""); return err },
		"a path as domain":          func() error { _, err := logs.Tail("budi", "../../etc/passwd", "access", 10, ""); return err },
		"an unknown kind":           func() error { _, err := logs.Tail("budi", "example.com", "passwd", 10, ""); return err },
		"too many lines":            func() error { _, err := logs.Tail("budi", "example.com", "access", MaxTailLines+1, ""); return err },
		"a download":                func() error { return logs.Download("sari", "example.com", "access", io.Discard) },
		"a report":                  func() error { return logs.Report(context.Background(), "budi", "other.org", io.Discard) },
	} {
		if err := call(); err == nil {
			t.Errorf("%s was read", name)
		}
	}
}

func TestALogThatIsALinkIsRefused(t *testing.T) {
	f, logs := newFixture(t)
	f.write(t, "secret", "root:x:0:0\n")
	must(t, os.Symlink(filepath.Join(f.logDir, "secret"), filepath.Join(f.logDir, "example.com.access.log")))

	if _, err := logs.Tail("budi", "example.com", "access", 10, ""); err == nil {
		t.Error("a log that is a link was read")
	}
}

func TestADownloadHasEveryKeptLogOldestFirst(t *testing.T) {
	f, logs := newFixture(t)
	f.write(t, "example.com.access.log.3.gz", "day 1\n")
	f.write(t, "example.com.access.log.2.gz", "day 2\n")
	f.write(t, "example.com.access.log.1", "day 3\n")
	f.write(t, "example.com.access.log", "today\n")
	f.write(t, "example.com.error.log", "not this one\n")

	var download bytes.Buffer
	if err := logs.Download("budi", "example.com", "access", &download); err != nil {
		t.Fatal(err)
	}

	unzipped, err := gzip.NewReader(&download)
	if err != nil {
		t.Fatal(err)
	}

	content, _ := io.ReadAll(unzipped)
	if string(content) != "day 1\nday 2\nday 3\ntoday\n" {
		t.Errorf("download = %q", content)
	}
}

func TestAReportIsMadeFromTheAccessLogs(t *testing.T) {
	f, logs := newFixture(t)

	if err := logs.Report(context.Background(), "budi", "example.com", io.Discard); !errors.Is(err, ErrNoVisits) {
		t.Errorf("a report without visits = %v", err)
	}

	f.write(t, "example.com.access.log.2.gz", "visit 1\n")
	f.write(t, "example.com.access.log", "visit 2\n")

	var report bytes.Buffer
	if err := logs.Report(context.Background(), "budi", "example.com", &report); err != nil {
		t.Fatal(err)
	}

	if f.input != "visit 1\nvisit 2\n" || report.String() != "<html>example.com</html>" {
		t.Errorf("GoAccess read %q and wrote %q", f.input, report.String())
	}
}

func TestAFailingReportDoesNotLeaveTheCopyHanging(t *testing.T) {
	f, logs := newFixture(t)
	f.write(t, "example.com.access.log", strings.Repeat("visit\n", 200000))

	// GoAccess stops without reading: the copy into it must not block.
	logs.cfg.GoAccess = func(context.Context, io.Reader, string, io.Writer) error { return errors.New("goaccess failed") }

	if err := logs.Report(context.Background(), "budi", "example.com", io.Discard); err == nil || err.Error() != "goaccess failed" {
		t.Errorf("report = %v", err)
	}
}

func must(t *testing.T, err error) {
	t.Helper()

	if err != nil {
		t.Fatal(err)
	}
}
