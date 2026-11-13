package procmon

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
	"time"
)

// fakeProc is a /proc with processes whose CPU times move on while List
// waits.
type fakeProc struct {
	t    *testing.T
	root string
	sent []string
}

type fakeProcess struct {
	pid, uid, ppid int
	comm, cmdline  string
	flags          int
	ticks          int
	start          int
	rssPages       int
}

func newFakeProc(t *testing.T) *fakeProc {
	t.Helper()

	f := &fakeProc{t: t, root: t.TempDir()}
	f.writeStat(1000, 2)
	f.file("meminfo", "MemTotal:        2048000 kB\nMemFree: 1 kB\n")

	return f
}

func (f *fakeProc) file(name, content string) {
	f.t.Helper()

	path := filepath.Join(f.root, name)
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		f.t.Fatal(err)
	}

	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		f.t.Fatal(err)
	}
}

func (f *fakeProc) writeStat(total, cpus int) {
	lines := fmt.Sprintf("cpu  %d 0 0 0 0 0 0 0 999 999\n", total)
	for i := range cpus {
		lines += fmt.Sprintf("cpu%d 1 0 0 0\n", i)
	}

	f.file("stat", lines+"intr 1\nbtime 1794300000\n")
}

func (f *fakeProc) add(p fakeProcess) {
	dir := fmt.Sprint(p.pid)
	// utime then stime: the ticks are split between them.
	f.file(dir+"/stat", fmt.Sprintf("%d (%s) S %d 0 0 0 0 %d 0 0 0 0 %d %d 0 0 20 0 1 0 %d 1000 0\n",
		p.pid, p.comm, p.ppid, p.flags, p.ticks-p.ticks/2, p.ticks/2, p.start))
	f.file(dir+"/status", fmt.Sprintf("Name:\t%s\nUid:\t%d\t%d\t%d\t%d\n", p.comm, p.uid, p.uid, p.uid, p.uid))
	f.file(dir+"/statm", fmt.Sprintf("9999 %d 0 0 0 0 0\n", p.rssPages))
	f.file(dir+"/cmdline", strings.ReplaceAll(p.cmdline, " ", "\x00"))
}

func (f *fakeProc) manager(between func()) *Manager {
	return New(Config{
		Proc:     f.root,
		Passwd:   filepath.Join(f.root, "passwd"),
		PageSize: 4096,
		Interval: time.Second,
		Sleep:    func(time.Duration) { between() },
		Open: func(pid int) (Handle, error) {
			if _, err := os.Stat(filepath.Join(f.root, fmt.Sprint(pid))); err != nil {
				return nil, err
			}

			return fakeHandle{f: f, pid: pid}, nil
		},
		Self: 4242,
	})
}

type fakeHandle struct {
	f   *fakeProc
	pid int
}

func (h fakeHandle) Signal(sig syscall.Signal) error {
	h.f.sent = append(h.f.sent, fmt.Sprintf("%d %v", h.pid, sig))

	return nil
}

func (fakeHandle) Close() error { return nil }

// server is a small server: init, a kernel thread, two processes of budi
// and one of sari whose PID is reused while List waits.
func server(t *testing.T) (*fakeProc, *Manager) {
	t.Helper()

	f := newFakeProc(t)
	f.file("passwd", "root:x:0:0::/root:/bin/bash\nbudi:x:1001:1001:rayxo:/home/budi:/usr/sbin/nologin\n")

	init := fakeProcess{pid: 1, uid: 0, comm: "systemd", cmdline: "/sbin/init", ticks: 5, start: 1, rssPages: 100}
	kthread := fakeProcess{pid: 2, uid: 0, comm: "kthreadd", flags: pfKthread, ticks: 5, start: 1}
	php := fakeProcess{pid: 100, uid: 1001, ppid: 1, comm: "php-fpm8.3", cmdline: "php-fpm: pool budi", ticks: 100, start: 500, rssPages: 2000}
	shell := fakeProcess{pid: 101, uid: 1001, ppid: 1, comm: "a (b) c", ticks: 10, start: 600, rssPages: 50}
	old := fakeProcess{pid: 200, uid: 1002, ppid: 1, comm: "old", cmdline: "old job", ticks: 1000, start: 700, rssPages: 10}

	for _, p := range []fakeProcess{init, kthread, php, shell, old} {
		f.add(p)
	}

	return f, f.manager(func() {
		// 100 ticks pass on 2 CPUs: budi's PHP uses 50 of them, and PID
		// 200 is now another process that has used 20.
		f.writeStat(1100, 2)
		php.ticks = 150
		f.add(php)
		f.add(fakeProcess{pid: 200, uid: 1002, ppid: 1, comm: "new", cmdline: "new job", ticks: 20, start: 900, rssPages: 10})
	})
}

func TestListMeasuresCPUOverTheIntervalBusiestFirst(t *testing.T) {
	_, monitor := server(t)

	snapshot, err := monitor.List(-1)
	if err != nil {
		t.Fatal(err)
	}

	var got []string
	for _, p := range snapshot.Processes {
		got = append(got, fmt.Sprintf("%d %s %.1f %d %q", p.PID, p.User, p.CPU, p.Memory, p.Command))
	}

	want := []string{
		`100 budi 100.0 8192000 "php-fpm: pool budi"`,
		`200 1002 40.0 40960 "new job"`,
		`1 root 0.0 409600 "/sbin/init"`,
		`101 budi 0.0 204800 "[a (b) c]"`,
	}

	if strings.Join(got, "\n") != strings.Join(want, "\n") {
		t.Errorf("processes:\n%s\nwant:\n%s", strings.Join(got, "\n"), strings.Join(want, "\n"))
	}

	if snapshot.CPUs != 2 || snapshot.MemoryTotal != 2048000*1024 || snapshot.Total != 4 || snapshot.Truncated {
		t.Errorf("snapshot = cpus %d, memory %d, total %d, truncated %v", snapshot.CPUs, snapshot.MemoryTotal, snapshot.Total, snapshot.Truncated)
	}

	if budi := snapshot.Users[0]; budi.User != "budi" || budi.Processes != 2 || budi.CPU != 100 || budi.Memory != 8396800 {
		t.Errorf("first user = %+v", budi)
	}

	if started := snapshot.Processes[0].StartedAt; !started.Equal(time.Unix(1794300005, 0)) {
		t.Errorf("started at %v", started)
	}
}

func TestListCanShowOneAccount(t *testing.T) {
	_, monitor := server(t)

	snapshot, err := monitor.List(1001)
	if err != nil {
		t.Fatal(err)
	}

	if len(snapshot.Processes) != 2 || len(snapshot.Users) != 1 || snapshot.Users[0].User != "budi" {
		t.Errorf("snapshot of budi = %+v", snapshot)
	}
}

func TestKillOnlySignalsTheProcessThatWasListed(t *testing.T) {
	f, monitor := server(t)

	// The panel lists first; meanwhile PID 200 became another process.
	if _, err := monitor.List(-1); err != nil {
		t.Fatal(err)
	}

	if err := monitor.Kill(100, "500", "TERM"); err != nil {
		t.Fatal(err)
	}

	for name, call := range map[string]struct {
		pid    int
		start  string
		signal string
		want   error
	}{
		"a reused PID":    {200, "700", "KILL", ErrGone},
		"an ended one":    {300, "1", "KILL", ErrGone},
		"init":            {1, "1", "KILL", ErrProtected},
		"the agent":       {4242, "1", "KILL", ErrProtected},
		"a kernel thread": {2, "1", "KILL", ErrProtected},
	} {
		if err := monitor.Kill(call.pid, call.start, call.signal); !errors.Is(err, call.want) {
			t.Errorf("%s: %v, want %v", name, err, call.want)
		}
	}

	if err := monitor.Kill(100, "500", "HUP"); err == nil {
		t.Error("another signal was sent")
	}

	if strings.Join(f.sent, ",") != fmt.Sprintf("100 %v", syscall.SIGTERM) {
		t.Errorf("signals sent: %v", f.sent)
	}
}
