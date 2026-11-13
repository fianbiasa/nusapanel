// Package procmon lists the processes of the server for the panel's
// process monitor, with what each account uses together, and stops a
// process. CPU use is measured over a short interval between two looks at
// /proc, as a percentage of one CPU, like top shows it. Kernel threads are
// left out.
package procmon

import (
	"bufio"
	"cmp"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"syscall"
	"time"
	"unicode/utf8"
)

// MaxProcesses is the most processes a list returns, busiest first.
const MaxProcesses = 500

// maxCommandBytes cuts long command lines.
const maxCommandBytes = 512

// pfKthread is the flag of kernel threads in /proc/<pid>/stat.
const pfKthread = 0x00200000

// clockTicks is USER_HZ, the unit of times in /proc.
const clockTicks = 100

// ErrGone is returned for a process that has ended, or whose PID now
// belongs to another process.
var ErrGone = errors.New("the process has ended")

// ErrProtected is returned for a process that may not be stopped: init,
// the agent itself and kernel threads.
var ErrProtected = errors.New("this process may not be stopped")

// Signals are the signals the panel may send, by name.
var Signals = map[string]syscall.Signal{"TERM": syscall.SIGTERM, "KILL": syscall.SIGKILL}

// Process is one process.
type Process struct {
	PID   int    `json:"pid"`
	PPID  int    `json:"ppid"`
	User  string `json:"user"`
	UID   int    `json:"uid"`
	State string `json:"state"`
	// CPU is the use of the last interval, in percent of one CPU.
	CPU float64 `json:"cpu"`
	// Memory is the resident memory in bytes.
	Memory    int64     `json:"memory"`
	Command   string    `json:"command"`
	StartedAt time.Time `json:"started_at"`
	// Start tells the process apart from a later one with the same PID;
	// Kill needs it.
	Start string `json:"start"`
}

// UserUsage is what the processes of one user use together.
type UserUsage struct {
	User      string  `json:"user"`
	UID       int     `json:"uid"`
	Processes int     `json:"processes"`
	CPU       float64 `json:"cpu"`
	Memory    int64   `json:"memory"`
}

// Snapshot is the processes at one moment.
type Snapshot struct {
	Processes   []Process   `json:"processes"`
	Users       []UserUsage `json:"users"`
	CPUs        int         `json:"cpus"`
	MemoryTotal int64       `json:"memory_total"`
	// Total is how many processes there are; Truncated is true when only
	// the busiest MaxProcesses are listed.
	Total     int  `json:"total"`
	Truncated bool `json:"truncated"`
}

// Handle is an open process a signal can be sent through, so it reaches
// the process that was checked even when its PID is reused meanwhile.
type Handle interface {
	Signal(sig syscall.Signal) error
	Close() error
}

// Config holds where the Manager reads and how it stops processes.
type Config struct {
	// Proc is the proc filesystem, /proc.
	Proc string
	// Passwd names the users, /etc/passwd.
	Passwd string
	// PageSize is the size of a memory page in bytes.
	PageSize int64
	// Interval is the time between the two looks CPU use is measured over.
	Interval time.Duration
	Sleep    func(time.Duration)
	// Open returns a handle on process pid.
	Open func(pid int) (Handle, error)
	// Self is the agent's own PID.
	Self int
}

// Manager lists and stops processes.
type Manager struct {
	cfg Config
}

// New returns a Manager for cfg.
func New(cfg Config) *Manager {
	return &Manager{cfg: cfg}
}

// stat is what /proc/<pid>/stat tells.
type stat struct {
	comm   string
	state  string
	ppid   int
	kernel bool
	ticks  uint64
	start  string
}

// List returns the processes, busiest first, and what each user uses
// together; with uid at 0 or more, only that user's processes.
func (m *Manager) List(uid int) (Snapshot, error) {
	totalBefore, _, err := m.cpuTicks()
	if err != nil {
		return Snapshot{}, err
	}

	before := map[int]stat{}

	for _, pid := range m.pids() {
		if s, err := m.readStat(pid); err == nil {
			before[pid] = s
		}
	}

	m.cfg.Sleep(m.cfg.Interval)

	totalAfter, cpus, err := m.cpuTicks()
	if err != nil {
		return Snapshot{}, err
	}

	elapsed := float64(totalAfter - totalBefore)
	bootTime := m.bootTime()
	users := m.userNames()

	snapshot := Snapshot{Processes: []Process{}, Users: []UserUsage{}, CPUs: cpus, MemoryTotal: m.memoryTotal()}
	byUser := map[int]*UserUsage{}

	for _, pid := range m.pids() {
		s, err := m.readStat(pid)
		if err != nil || s.kernel {
			continue
		}

		owner, err := m.readUID(pid)
		if err != nil || (uid >= 0 && owner != uid) {
			continue
		}

		process := Process{
			PID:     pid,
			PPID:    s.ppid,
			UID:     owner,
			User:    cmp.Or(users[owner], strconv.Itoa(owner)),
			State:   s.state,
			Memory:  m.readMemory(pid),
			Command: m.readCommand(pid, s.comm),
			Start:   s.start,
		}

		if ticks, err := strconv.ParseUint(s.start, 10, 64); err == nil && bootTime > 0 {
			process.StartedAt = time.Unix(bootTime+int64(ticks/clockTicks), 0).UTC()
		}

		if elapsed > 0 {
			used := s.ticks

			// A process that started during the interval spent all its
			// time in it.
			if previous, ok := before[pid]; ok && previous.start == s.start {
				used -= previous.ticks
			}

			process.CPU = round(float64(used) / elapsed * float64(cpus) * 100)
		}

		usage := byUser[owner]
		if usage == nil {
			usage = &UserUsage{User: process.User, UID: owner}
			byUser[owner] = usage
		}

		usage.Processes++
		usage.CPU += process.CPU
		usage.Memory += process.Memory

		snapshot.Processes = append(snapshot.Processes, process)
	}

	slices.SortStableFunc(snapshot.Processes, func(a, b Process) int {
		return cmp.Or(cmp.Compare(b.CPU, a.CPU), cmp.Compare(b.Memory, a.Memory), cmp.Compare(a.PID, b.PID))
	})

	snapshot.Total = len(snapshot.Processes)

	if snapshot.Total > MaxProcesses {
		snapshot.Processes = snapshot.Processes[:MaxProcesses]
		snapshot.Truncated = true
	}

	for _, usage := range byUser {
		usage.CPU = round(usage.CPU)
		snapshot.Users = append(snapshot.Users, *usage)
	}

	slices.SortStableFunc(snapshot.Users, func(a, b UserUsage) int {
		return cmp.Or(cmp.Compare(b.CPU, a.CPU), cmp.Compare(b.Memory, a.Memory), cmp.Compare(a.User, b.User))
	})

	return snapshot, nil
}

// Kill sends signal ("TERM" or "KILL") to process pid, if it is still the
// process that started at start.
func (m *Manager) Kill(pid int, start, signal string) error {
	sig, ok := Signals[signal]
	if !ok {
		return fmt.Errorf("signal must be TERM or KILL, not %q", signal)
	}

	if pid <= 1 || pid == m.cfg.Self {
		return ErrProtected
	}

	handle, err := m.cfg.Open(pid)
	if err != nil {
		return ErrGone
	}
	defer handle.Close()

	// Checked after the handle is open: from here on, a reused PID cannot
	// receive the signal.
	s, err := m.readStat(pid)
	if err != nil || s.start != start {
		return ErrGone
	}

	if s.kernel {
		return ErrProtected
	}

	if err := handle.Signal(sig); err != nil {
		if errors.Is(err, syscall.ESRCH) {
			return ErrGone
		}

		return fmt.Errorf("signal process %d: %w", pid, err)
	}

	return nil
}

// pids lists the processes in Proc.
func (m *Manager) pids() []int {
	entries, err := os.ReadDir(m.cfg.Proc)
	if err != nil {
		return nil
	}

	var pids []int

	for _, entry := range entries {
		if pid, err := strconv.Atoi(entry.Name()); err == nil && pid > 0 {
			pids = append(pids, pid)
		}
	}

	return pids
}

// readStat parses /proc/<pid>/stat. The command name is between the first
// "(" and the last ")", as it may hold both.
func (m *Manager) readStat(pid int) (stat, error) {
	content, err := os.ReadFile(m.path(pid, "stat"))
	if err != nil {
		return stat{}, err
	}

	text := string(content)
	open, end := strings.IndexByte(text, '('), strings.LastIndexByte(text, ')')

	if open < 0 || end < open || end+2 > len(text) {
		return stat{}, fmt.Errorf("unexpected stat of %d", pid)
	}

	fields := strings.Fields(text[end+2:])
	if len(fields) < 20 {
		return stat{}, fmt.Errorf("unexpected stat of %d", pid)
	}

	ppid, _ := strconv.Atoi(fields[1])
	flags, _ := strconv.ParseUint(fields[6], 10, 64)
	user, _ := strconv.ParseUint(fields[11], 10, 64)
	system, _ := strconv.ParseUint(fields[12], 10, 64)

	return stat{
		comm:   text[open+1 : end],
		state:  fields[0],
		ppid:   ppid,
		kernel: flags&pfKthread != 0,
		ticks:  user + system,
		start:  fields[19],
	}, nil
}

// readUID is the effective user of the process.
func (m *Manager) readUID(pid int) (int, error) {
	file, err := os.Open(m.path(pid, "status"))
	if err != nil {
		return 0, err
	}
	defer file.Close()

	scanner := bufio.NewScanner(file)
	for scanner.Scan() {
		if rest, ok := strings.CutPrefix(scanner.Text(), "Uid:"); ok {
			fields := strings.Fields(rest)
			if len(fields) >= 2 {
				return strconv.Atoi(fields[1])
			}
		}
	}

	return 0, fmt.Errorf("no Uid in the status of %d", pid)
}

// readMemory is the resident memory of the process in bytes.
func (m *Manager) readMemory(pid int) int64 {
	content, err := os.ReadFile(m.path(pid, "statm"))
	if err != nil {
		return 0
	}

	fields := strings.Fields(string(content))
	if len(fields) < 2 {
		return 0
	}

	pages, _ := strconv.ParseInt(fields[1], 10, 64)

	return pages * m.cfg.PageSize
}

// readCommand is the command line, or the name in brackets when it has
// none.
func (m *Manager) readCommand(pid int, comm string) string {
	content, _ := os.ReadFile(m.path(pid, "cmdline"))
	command := strings.TrimSpace(strings.ReplaceAll(string(content), "\x00", " "))

	if command == "" {
		command = "[" + comm + "]"
	}

	command = strings.ToValidUTF8(command, "�")

	if len(command) > maxCommandBytes {
		cut := maxCommandBytes
		for cut > 0 && !utf8.RuneStart(command[cut]) {
			cut--
		}

		command = command[:cut] + "…"
	}

	return command
}

// cpuTicks is the time all CPUs have spent, in ticks, and how many CPUs
// there are.
func (m *Manager) cpuTicks() (total uint64, cpus int, err error) {
	content, err := os.ReadFile(filepath.Join(m.cfg.Proc, "stat"))
	if err != nil {
		return 0, 0, fmt.Errorf("read the CPU times: %w", err)
	}

	for line := range strings.Lines(string(content)) {
		fields := strings.Fields(line)

		switch {
		case len(fields) > 1 && fields[0] == "cpu":
			// user, nice, system, idle, iowait, irq, softirq, steal; guest
			// time is already in user.
			for _, field := range fields[1:min(len(fields), 9)] {
				value, _ := strconv.ParseUint(field, 10, 64)
				total += value
			}
		case len(fields) > 0 && strings.HasPrefix(fields[0], "cpu"):
			cpus++
		}
	}

	return total, max(cpus, 1), nil
}

// bootTime is when the server started, in Unix seconds, or 0.
func (m *Manager) bootTime() int64 {
	content, _ := os.ReadFile(filepath.Join(m.cfg.Proc, "stat"))

	for line := range strings.Lines(string(content)) {
		if rest, ok := strings.CutPrefix(line, "btime "); ok {
			value, _ := strconv.ParseInt(strings.TrimSpace(rest), 10, 64)

			return value
		}
	}

	return 0
}

// memoryTotal is the server's memory in bytes, or 0.
func (m *Manager) memoryTotal() int64 {
	content, _ := os.ReadFile(filepath.Join(m.cfg.Proc, "meminfo"))

	for line := range strings.Lines(string(content)) {
		if rest, ok := strings.CutPrefix(line, "MemTotal:"); ok {
			fields := strings.Fields(rest)
			if len(fields) > 0 {
				kb, _ := strconv.ParseInt(fields[0], 10, 64)

				return kb * 1024
			}
		}
	}

	return 0
}

// userNames maps UIDs to names from Passwd.
func (m *Manager) userNames() map[int]string {
	names := map[int]string{}

	content, err := os.ReadFile(m.cfg.Passwd)
	if err != nil {
		return names
	}

	for line := range strings.Lines(string(content)) {
		fields := strings.Split(strings.TrimSpace(line), ":")
		if len(fields) < 3 {
			continue
		}

		if uid, err := strconv.Atoi(fields[2]); err == nil {
			if _, seen := names[uid]; !seen {
				names[uid] = fields[0]
			}
		}
	}

	return names
}

func (m *Manager) path(pid int, name string) string {
	return filepath.Join(m.cfg.Proc, strconv.Itoa(pid), name)
}

// round keeps one decimal.
func round(value float64) float64 {
	return float64(int64(value*10+0.5)) / 10
}
