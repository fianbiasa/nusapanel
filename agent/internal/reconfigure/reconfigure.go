// Package reconfigure runs the installed setup.sh again to give the server
// a new hostname. setup.sh restarts the agent on the way, so it runs in a
// systemd unit of its own (systemd-run), never as the agent's child; its
// output and exit code go to files the agent reads back for the panel.
package reconfigure

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"strconv"
	"strings"
	"time"

	"rayxo/agent/internal/runner"
)

const (
	systemdRunProgram = "/usr/bin/systemd-run"
	systemctlProgram  = "/usr/bin/systemctl"

	// logLines is how much of setup.sh's output Status returns.
	logLines = 40
	// failurePrefix starts the line setup.sh's die() prints.
	failurePrefix = "GAGAL: "
)

// script runs setup.sh with the hostname, its output to the log and its exit
// code to the exit file, both readable by root only. The values arrive as
// positional parameters, never inside the script text.
const script = `umask 077; "$1" --reconfigure --yes --hostname "$2" >"$3" 2>&1; echo $? >"$4"`

// ErrRunning means setup.sh is still running from an earlier request.
var ErrRunning = errors.New("setup.sh is already running")

// Config holds where setup.sh is and where its run is recorded.
type Config struct {
	// Script is the setup.sh of the installed release.
	Script string
	// Unit names the transient systemd service setup.sh runs in.
	Unit string
	// LogFile receives setup.sh's output; StateFile records what was asked
	// and when, and StateFile+".exit" setup.sh's exit code.
	LogFile   string
	StateFile string
	// Run runs a program without a shell, like runner.Run.
	Run func(ctx context.Context, program string, args ...string) ([]byte, error)
	// Now returns the current time; nil means time.Now.
	Now func() time.Time
}

// DefaultConfig returns the locations and operations used on a server.
func DefaultConfig() Config {
	return Config{
		Script:    "/usr/local/rayxo/current/setup.sh",
		Unit:      "rayxo-reconfigure",
		LogFile:   "/var/log/rayxo/reconfigure.log",
		StateFile: "/var/lib/rayxo/reconfigure.json",
		Run:       runner.Run,
	}
}

// State is the latest run of setup.sh.
type State struct {
	Hostname  string    `json:"hostname"`
	StartedAt time.Time `json:"started_at"`
	Running   bool      `json:"running"`
	// ExitCode is nil while setup.sh runs, or when its end was not recorded.
	ExitCode *int `json:"exit_code"`
	// Error is why setup.sh stopped, from its last "GAGAL:" line.
	Error string `json:"error"`
	// Log is the end of setup.sh's output.
	Log []string `json:"log"`
}

// Manager starts setup.sh and reports on it.
type Manager struct {
	cfg Config
}

// New returns a Manager for cfg. It does not touch the system.
func New(cfg Config) *Manager {
	return &Manager{cfg: cfg}
}

// Start runs setup.sh for hostname, which the caller has validated, in the
// background. It refuses while an earlier run is still going (ErrRunning).
func (m *Manager) Start(ctx context.Context, hostname string) error {
	if m.running(ctx) {
		return ErrRunning
	}

	for _, path := range []string{m.cfg.LogFile, m.exitFile()} {
		if err := os.Remove(path); err != nil && !errors.Is(err, os.ErrNotExist) {
			return fmt.Errorf("remove %s: %w", path, err)
		}
	}

	state, err := json.Marshal(State{Hostname: hostname, StartedAt: m.now().UTC()})
	if err != nil {
		return err
	}

	if err := os.WriteFile(m.cfg.StateFile, state, 0o600); err != nil {
		return fmt.Errorf("write %s: %w", m.cfg.StateFile, err)
	}

	_, err = m.cfg.Run(ctx, systemdRunProgram,
		"--unit="+m.cfg.Unit, "--collect", "--quiet", "--description=RAYXO: change the hostname",
		"/bin/sh", "-c", script, m.cfg.Unit, m.cfg.Script, hostname, m.cfg.LogFile, m.exitFile())
	if err != nil {
		return fmt.Errorf("start setup.sh: %w", err)
	}

	return nil
}

// Status reports the latest run, or nil when setup.sh never ran from here.
func (m *Manager) Status(ctx context.Context) (*State, error) {
	content, err := os.ReadFile(m.cfg.StateFile)
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}

	if err != nil {
		return nil, fmt.Errorf("read %s: %w", m.cfg.StateFile, err)
	}

	var state State
	if err := json.Unmarshal(content, &state); err != nil {
		return nil, fmt.Errorf("parse %s: %w", m.cfg.StateFile, err)
	}

	state.Running = m.running(ctx)
	state.Log = tail(m.cfg.LogFile, logLines)

	for _, line := range state.Log {
		if message, ok := strings.CutPrefix(line, failurePrefix); ok {
			state.Error = message
		}
	}

	if code, err := os.ReadFile(m.exitFile()); err == nil && !state.Running {
		if value, err := strconv.Atoi(strings.TrimSpace(string(code))); err == nil {
			state.ExitCode = &value
		}
	}

	return &state, nil
}

// running reports whether setup.sh's unit is still active.
func (m *Manager) running(ctx context.Context) bool {
	_, err := m.cfg.Run(ctx, systemctlProgram, "is-active", "--quiet", m.cfg.Unit+".service")

	return err == nil
}

func (m *Manager) exitFile() string {
	return m.cfg.StateFile + ".exit"
}

func (m *Manager) now() time.Time {
	if m.cfg.Now != nil {
		return m.cfg.Now()
	}

	return time.Now()
}

// tail returns the last n non-empty lines of the file at path, each at most
// 300 bytes; a missing file has none.
func tail(path string, n int) []string {
	file, err := os.Open(path)
	if err != nil {
		return []string{}
	}
	defer file.Close()

	lines := []string{}
	scanner := bufio.NewScanner(file)
	scanner.Buffer(make([]byte, 0, 64*1024), 1024*1024)

	for scanner.Scan() {
		line := strings.TrimRight(scanner.Text(), " \t\r")
		if strings.TrimSpace(line) == "" {
			continue
		}

		if len(line) > 300 {
			line = line[:300]
		}

		lines = append(lines, line)
		if len(lines) > n {
			lines = lines[1:]
		}
	}

	return lines
}
