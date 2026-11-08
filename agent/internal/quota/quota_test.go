package quota

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io/fs"
	"log/slog"
	"os"
	"path/filepath"
	"reflect"
	"slices"
	"strings"
	"testing"

	"nusapanel/agent/internal/runner"
)

// sampleReport is what `repquota -u -v -n -O csv /` prints with quota 4.06,
// including a warning written to stderr before the report.
const sampleReport = `repquota: Cannot stat() mounted device /dev/root: No such file or directory
User,BlockStatus,FileStatus,BlockUsed,BlockSoftLimit,BlockHardLimit,BlockGrace,FileUsed,FileSoftLimit,FileHardLimit,FileGrace
#0,ok,ok,2349060,0,0,,96214,0,0,
#1001,ok,ok,1234,1048576,1048576,,56,50000,50000,
#1002,hard,ok,2097156,2097152,2097152,none,800,0,0,
#1003,ok,ok,0,0,0,,0,0,0,
`

// fakeTools fakes quotaon, setquota and repquota.
type fakeTools struct {
	calls []string
	// state is what quotaon -p reports: "on", "off", "none" (mounted
	// without quota options) or "missing" (the quota tools are not
	// installed).
	state    string
	report   string
	failWith error
}

func (f *fakeTools) run(_ context.Context, program string, args ...string) ([]byte, error) {
	name := filepath.Base(program)
	f.calls = append(f.calls, name+" "+strings.Join(args, " "))

	switch name {
	case "quotaon":
		switch f.state {
		case "on":
			return []byte("user quota on / (/dev/vda1) is on\n"),
				&runner.Error{Program: program, ExitCode: 1, Err: errors.New("exit status 1")}
		case "off":
			return []byte("user quota on / (/dev/vda1) is off\n"), nil
		case "none":
			return []byte("quotaon: Mountpoint (or device) / not found or has no quota enabled.\n"), nil
		case "missing":
			return nil, &runner.Error{Program: program, ExitCode: -1, Err: fmt.Errorf("fork/exec %s: %w", program, fs.ErrNotExist)}
		}

		return []byte("quotaon: something unexpected\n"), &runner.Error{Program: program, ExitCode: 2, Err: errors.New("exit status 2")}
	case "setquota":
		return nil, f.failWith
	case "repquota":
		return []byte(f.report), f.failWith
	}

	return nil, fmt.Errorf("unexpected program %s", program)
}

func newManager(tools *fakeTools, logs *bytes.Buffer) *Manager {
	return New(Config{
		HomeRoot: "/home",
		MountPoint: func(path string) (string, error) {
			if path != "/home" {
				return "", fmt.Errorf("unexpected path %s", path)
			}

			return "/", nil
		},
		Run:    tools.run,
		Logger: slog.New(slog.NewTextHandler(logs, nil)),
	})
}

func assertCalls(t *testing.T, tools *fakeTools, want ...string) {
	t.Helper()

	if !slices.Equal(tools.calls, want) {
		t.Fatalf("calls = %q, want %q", tools.calls, want)
	}
}

func TestStatus(t *testing.T) {
	cases := map[string]Status{
		"on":      {MountPoint: "/", Enabled: true},
		"off":     {MountPoint: "/", Reason: "user quotas are off on /"},
		"none":    {MountPoint: "/", Reason: "/ is not mounted with user quota options"},
		"missing": {MountPoint: "/", Reason: "the quota tools are not installed"},
	}

	for state, want := range cases {
		tools := &fakeTools{state: state}

		status, err := newManager(tools, &bytes.Buffer{}).Status(context.Background())
		if err != nil {
			t.Fatalf("%s: %v", state, err)
		}

		if status != want {
			t.Fatalf("%s: status = %+v, want %+v", state, status, want)
		}

		assertCalls(t, tools, "quotaon -p -u /")
	}
}

func TestStatusFailsOnUnknownState(t *testing.T) {
	tools := &fakeTools{state: "broken"}

	if _, err := newManager(tools, &bytes.Buffer{}).Status(context.Background()); err == nil {
		t.Fatal("an unreadable quotaon output was accepted")
	}

	killed := New(Config{
		HomeRoot:   "/home",
		MountPoint: func(string) (string, error) { return "/", nil },
		Run: func(_ context.Context, program string, _ ...string) ([]byte, error) {
			return []byte("user quota on / (/dev/vda1) is on\n"),
				&runner.Error{Program: program, ExitCode: -1, Err: context.DeadlineExceeded}
		},
	})
	if _, err := killed.Status(context.Background()); !errors.Is(err, context.DeadlineExceeded) {
		t.Fatalf("a killed quotaon was not an error: %v", err)
	}

	lost := New(Config{
		HomeRoot:   "/home",
		MountPoint: func(string) (string, error) { return "", fs.ErrNotExist },
		Run:        tools.run,
	})
	if _, err := lost.Status(context.Background()); err == nil {
		t.Fatal("a missing home root was not an error")
	}
}

func TestSetUserLimitsUsesKiBWithoutGrace(t *testing.T) {
	cases := []struct {
		limits Limits
		want   string
	}{
		{Limits{BlocksKB: 1048576, Inodes: 50000}, "setquota -u -- budi 1048576 1048576 50000 50000 /"},
		{Limits{}, "setquota -u -- budi 0 0 0 0 /"},
		{Limits{BlocksKB: MaxBlocksKB, Inodes: MaxInodes}, "setquota -u -- budi 9007199254740991 9007199254740991 9223372036854775806 9223372036854775806 /"},
	}

	for _, test := range cases {
		tools := &fakeTools{state: "on"}

		applied, err := newManager(tools, &bytes.Buffer{}).SetUserLimits(context.Background(), "budi", test.limits)
		if err != nil || !applied {
			t.Fatalf("SetUserLimits(%+v) = %v, %v", test.limits, applied, err)
		}

		assertCalls(t, tools, "quotaon -p -u /", test.want)
	}
}

func TestSetUserLimitsDoesNothingWhenQuotasAreOff(t *testing.T) {
	for _, state := range []string{"off", "none", "missing"} {
		tools := &fakeTools{state: state}
		logs := &bytes.Buffer{}

		applied, err := newManager(tools, logs).SetUserLimits(context.Background(), "budi", Limits{BlocksKB: 1024})
		if err != nil || applied {
			t.Fatalf("%s: SetUserLimits = %v, %v; want false, nil", state, applied, err)
		}

		assertCalls(t, tools, "quotaon -p -u /")

		if !strings.Contains(logs.String(), "disk quota not applied") {
			t.Fatalf("%s: the reason was not logged: %s", state, logs)
		}
	}
}

func TestSetUserLimitsRejectsBadInput(t *testing.T) {
	invalid := map[string]Limits{
		"root":   {},
		"Budi":   {},
		"-u":     {},
		"budi":   {BlocksKB: -1},
		"budi ":  {},
		"budi2":  {Inodes: -1},
		"budi3":  {BlocksKB: MaxBlocksKB + 1},
		"budi4":  {Inodes: MaxInodes + 1},
		"../etc": {},
	}

	for user, limits := range invalid {
		tools := &fakeTools{state: "on"}

		if _, err := newManager(tools, &bytes.Buffer{}).SetUserLimits(context.Background(), user, limits); err == nil {
			t.Fatalf("SetUserLimits(%q, %+v) was accepted", user, limits)
		}

		assertCalls(t, tools)
	}
}

func TestSetUserLimitsReportsSetquotaFailures(t *testing.T) {
	tools := &fakeTools{state: "on", failWith: &runner.Error{Program: setquotaProgram, ExitCode: 1, Err: errors.New("exit status 1")}}

	applied, err := newManager(tools, &bytes.Buffer{}).SetUserLimits(context.Background(), "budi", Limits{BlocksKB: 1024})
	if err == nil || applied {
		t.Fatalf("SetUserLimits = %v, %v; want an error", applied, err)
	}
}

func TestClearUserLimits(t *testing.T) {
	tools := &fakeTools{state: "on"}
	newManager(tools, &bytes.Buffer{}).ClearUserLimits(context.Background(), "budi")
	assertCalls(t, tools, "quotaon -p -u /", "setquota -u -- budi 0 0 0 0 /")

	for _, state := range []string{"off", "none", "missing"} {
		tools := &fakeTools{state: state}
		logs := &bytes.Buffer{}
		newManager(tools, logs).ClearUserLimits(context.Background(), "budi")
		assertCalls(t, tools, "quotaon -p -u /")

		if logs.Len() != 0 {
			t.Fatalf("%s: quotas that are off were logged as a problem: %s", state, logs)
		}
	}

	tools = &fakeTools{state: "on", failWith: errors.New("setquota exited with code 1")}
	logs := &bytes.Buffer{}
	newManager(tools, logs).ClearUserLimits(context.Background(), "budi")

	if !strings.Contains(logs.String(), "disk quota not cleared") {
		t.Fatalf("a failure was not logged: %s", logs)
	}
}

func TestReport(t *testing.T) {
	tools := &fakeTools{state: "on", report: sampleReport}
	logs := &bytes.Buffer{}

	report, err := newManager(tools, logs).Report(context.Background())
	if err != nil {
		t.Fatalf("report failed: %v", err)
	}

	assertCalls(t, tools, "quotaon -p -u /", "repquota -u -v -n -O csv /")

	want := Report{
		Status: Status{MountPoint: "/", Enabled: true},
		Users: []Usage{
			{UID: 0, BlocksKB: 2349060, Inodes: 96214},
			{UID: 1001, BlocksKB: 1234, Inodes: 56, BlockLimitKB: 1048576, InodeLimit: 50000},
			{UID: 1002, BlocksKB: 2097156, Inodes: 800, BlockLimitKB: 2097152},
			{UID: 1003},
		},
	}
	if !reflect.DeepEqual(report, want) {
		t.Fatalf("report = %+v, want %+v", report, want)
	}

	if !strings.Contains(logs.String(), "Cannot stat()") {
		t.Fatalf("the skipped warning was not logged: %s", logs)
	}
}

func TestReportWhenQuotasAreOff(t *testing.T) {
	for _, state := range []string{"off", "none", "missing"} {
		tools := &fakeTools{state: state}

		report, err := newManager(tools, &bytes.Buffer{}).Report(context.Background())
		if err != nil {
			t.Fatalf("%s: %v", state, err)
		}

		if report.Enabled || report.MountPoint != "/" || report.Users != nil {
			t.Fatalf("%s: report = %+v", state, report)
		}

		assertCalls(t, tools, "quotaon -p -u /")
	}
}

func TestReportFailures(t *testing.T) {
	failing := &fakeTools{state: "on", failWith: errors.New("repquota exited with code 1")}
	if _, err := newManager(failing, &bytes.Buffer{}).Report(context.Background()); err == nil {
		t.Fatal("a failed repquota was not an error")
	}

	headless := &fakeTools{state: "on", report: "#1001,ok,ok,1,0,0,,1,0,0,\n"}
	if _, err := newManager(headless, &bytes.Buffer{}).Report(context.Background()); err == nil {
		t.Fatal("a report without header was accepted")
	}
}

func TestParseReportFindsColumnsByName(t *testing.T) {
	output := "User,FileUsed,FileHardLimit,BlockUsed,BlockHardLimit\n#1001,7,9,100,200\n"

	users, skipped, err := parseReport([]byte(output))
	if err != nil {
		t.Fatalf("parse failed: %v", err)
	}

	want := []Usage{{UID: 1001, BlocksKB: 100, BlockLimitKB: 200, Inodes: 7, InodeLimit: 9}}
	if !reflect.DeepEqual(users, want) || len(skipped) != 0 {
		t.Fatalf("users = %+v, skipped = %q", users, skipped)
	}
}

func TestParseReportSkipsMalformedLines(t *testing.T) {
	header := "User,BlockStatus,FileStatus,BlockUsed,BlockSoftLimit,BlockHardLimit,BlockGrace,FileUsed,FileSoftLimit,FileHardLimit,FileGrace\n"
	malformed := []string{
		"budi,ok,ok,1,0,0,,1,0,0,",
		"#budi,ok,ok,1,0,0,,1,0,0,",
		"#1001,ok,ok,1,0,0,,1,0,0",
		"#1001,ok,ok,1,0,0,,1,0,0,,",
		"#1001,ok,ok,1K,0,0,,1,0,0,",
		"#4294967296,ok,ok,1,0,0,,1,0,0,",
		"#-1,ok,ok,1,0,0,,1,0,0,",
	}

	users, skipped, err := parseReport([]byte(header + strings.Join(malformed, "\n") + "\n#1001,ok,ok,-4,0,0,,1,0,0,\n"))
	if err != nil {
		t.Fatalf("parse failed: %v", err)
	}

	if !slices.Equal(skipped, malformed) {
		t.Fatalf("skipped = %q, want %q", skipped, malformed)
	}

	if want := []Usage{{UID: 1001, Inodes: 1}}; !reflect.DeepEqual(users, want) {
		t.Fatalf("users = %+v, want %+v", users, want)
	}

	if _, _, err := parseReport([]byte("User,BlockUsed\n")); err == nil {
		t.Fatal("a header without the needed columns was accepted")
	}
}

func TestParseQuotaStateMatchesTheMountPointOnly(t *testing.T) {
	output := []byte("user quota on /home (/dev/vdb1) is on\nuser quota on / (/dev/vda1) is off\n")

	enabled, _, err := parseQuotaState(output, "/")
	if err != nil || enabled {
		t.Fatalf("parseQuotaState = %v, %v; want off", enabled, err)
	}
}

func TestMountPoint(t *testing.T) {
	root, err := mountPoint("/")
	if err != nil || root != "/" {
		t.Fatalf("mountPoint(/) = %q, %v", root, err)
	}

	dir := t.TempDir()

	found, err := mountPoint(dir)
	if err != nil {
		t.Fatalf("mountPoint(%s): %v", dir, err)
	}

	resolved, err := filepath.EvalSymlinks(dir)
	if err != nil {
		t.Fatal(err)
	}

	if found != "/" && found != resolved && !strings.HasPrefix(resolved, found+string(os.PathSeparator)) {
		t.Fatalf("mountPoint(%s) = %q, which does not hold it", dir, found)
	}

	if _, err := mountPoint(filepath.Join(dir, "missing")); err == nil {
		t.Fatal("a missing path has a mount point")
	}
}
