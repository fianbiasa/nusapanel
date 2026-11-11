// Package panelport moves the panel to another port without locking the
// admin out. The new port first opens next to the current one; it becomes
// the panel's port only when the admin confirms through it (setup.sh then
// writes every config for it), and a port not confirmed in time, or whose
// setup.sh run failed, is closed again.
package panelport

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"os"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"

	"rayxo/agent/internal/runner"
)

const (
	nginxProgram     = "/usr/sbin/nginx"
	systemctlProgram = "/usr/bin/systemctl"

	// DefaultPort is the panel's port until another is chosen.
	DefaultPort = 2222

	// settle is how long a confirmed trial waits for setup.sh to start
	// before a stopped setup.sh counts as a failed run.
	settle = 30 * time.Second
)

var (
	// ErrSamePort means the port is the panel's already.
	ErrSamePort = errors.New("the panel already uses this port")
	// ErrReservedPort means the port belongs to another service of the
	// server, or is below 1024.
	ErrReservedPort = errors.New("the port is reserved")
	// ErrPortInUse means something listens on the port.
	ErrPortInUse = errors.New("the port is in use")
	// ErrTrialRunning means another port is being tried.
	ErrTrialRunning = errors.New("another port is being tried")
	// ErrNoTrial means no port is being tried.
	ErrNoTrial = errors.New("no port is being tried")
	// ErrTrialExpired means the trial ended before it was confirmed.
	ErrTrialExpired = errors.New("the trial of the port ended")
	// ErrConfigRejected means Nginx refused the trial's configuration.
	ErrConfigRejected = errors.New("nginx rejected the configuration")
)

// listenPattern finds the panel's listen directives, IPv4 and IPv6.
var listenPattern = regexp.MustCompile(`(?m)^(\s*listen\s+(?:\[::\]:)?)(\d+)(\s)`)

// Config holds where the panel's Nginx site and the trial live.
type Config struct {
	// SiteFile is the panel's Nginx site, rendered by setup.sh.
	SiteFile string
	// TrialFile is the extra site serving the panel on the port tried.
	TrialFile string
	// PortFile is where setup.sh keeps the panel's port.
	PortFile string
	// StateFile records the trial.
	StateFile string
	// Window is how long a port is tried before it closes again.
	Window time.Duration
	// Run runs a program without a shell, like runner.Run.
	Run func(ctx context.Context, program string, args ...string) ([]byte, error)
	// Now returns the current time; nil means time.Now.
	Now func() time.Time
	// Free reports whether nothing listens on port; nil binds it briefly.
	Free func(port int) bool
	// Logger receives what Watch does; nil means slog.Default().
	Logger *slog.Logger
}

// DefaultConfig returns the locations and operations used on a server.
func DefaultConfig() Config {
	return Config{
		SiteFile:  "/etc/nginx/sites-available/rayxo.conf",
		TrialFile: "/etc/nginx/sites-enabled/rayxo-panel-trial.conf",
		PortFile:  "/etc/rayxo/panel-port",
		StateFile: "/var/lib/rayxo/panel-port-trial.json",
		Window:    5 * time.Minute,
		Run:       runner.Run,
	}
}

// Trial is a port being tried for the panel.
type Trial struct {
	Port        int        `json:"port"`
	Previous    int        `json:"previous"`
	ExpiresAt   time.Time  `json:"expires_at"`
	Confirmed   bool       `json:"confirmed"`
	ConfirmedAt *time.Time `json:"confirmed_at,omitempty"`
}

// Manager tries, confirms and closes panel ports.
type Manager struct {
	cfg Config
	mu  sync.Mutex
}

// New returns a Manager for cfg. It does not touch the system.
func New(cfg Config) *Manager {
	return &Manager{cfg: cfg}
}

// Reserved reports whether port may not become the panel's: below 1024,
// or used by another service of the server (MariaDB, Redis, Apache behind
// Nginx, PowerDNS's API, OpenDKIM, Rspamd, FTP's passive ports).
func Reserved(port int) bool {
	switch {
	case port < 1024 || port > 65535:
		return true
	case port == 3306 || port == 6379 || port == 8080 || port == 8081 || port == 8891:
		return true
	case port >= 11332 && port <= 11334:
		return true
	case port >= 35000 && port <= 35999:
		return true
	}

	return false
}

// Current returns the panel's port.
func (m *Manager) Current() int {
	if content, err := os.ReadFile(m.cfg.PortFile); err == nil {
		if port, err := strconv.Atoi(strings.TrimSpace(string(content))); err == nil && port > 0 {
			return port
		}
	}

	if content, err := os.ReadFile(m.cfg.SiteFile); err == nil {
		if match := listenPattern.FindSubmatch(content); match != nil {
			if port, err := strconv.Atoi(string(match[2])); err == nil {
				return port
			}
		}
	}

	return DefaultPort
}

// Pending returns the port being tried, or nil.
func (m *Manager) Pending() (*Trial, error) {
	content, err := os.ReadFile(m.cfg.StateFile)
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}

	if err != nil {
		return nil, fmt.Errorf("read %s: %w", m.cfg.StateFile, err)
	}

	var trial Trial
	if err := json.Unmarshal(content, &trial); err != nil {
		return nil, fmt.Errorf("parse %s: %w", m.cfg.StateFile, err)
	}

	return &trial, nil
}

// Open starts serving the panel on port as well, for Window.
func (m *Manager) Open(ctx context.Context, port int) (Trial, error) {
	m.mu.Lock()
	defer m.mu.Unlock()

	current := m.Current()

	switch {
	case Reserved(port):
		return Trial{}, fmt.Errorf("%w: %d", ErrReservedPort, port)
	case port == current:
		return Trial{}, fmt.Errorf("%w: %d", ErrSamePort, port)
	}

	if pending, err := m.Pending(); err != nil {
		return Trial{}, err
	} else if pending != nil {
		return Trial{}, fmt.Errorf("%w: %d", ErrTrialRunning, pending.Port)
	}

	if !m.free(port) {
		return Trial{}, fmt.Errorf("%w: %d", ErrPortInUse, port)
	}

	site, err := os.ReadFile(m.cfg.SiteFile)
	if err != nil {
		return Trial{}, fmt.Errorf("read the panel's site: %w", err)
	}

	trialSite, err := retarget(site, current, port)
	if err != nil {
		return Trial{}, err
	}

	if err := os.WriteFile(m.cfg.TrialFile, trialSite, 0o644); err != nil {
		return Trial{}, fmt.Errorf("write %s: %w", m.cfg.TrialFile, err)
	}

	if _, err := m.cfg.Run(ctx, nginxProgram, "-t"); err != nil {
		_ = os.Remove(m.cfg.TrialFile)

		return Trial{}, fmt.Errorf("%w: %v", ErrConfigRejected, err)
	}

	if _, err := m.cfg.Run(ctx, systemctlProgram, "reload", "nginx"); err != nil {
		_ = os.Remove(m.cfg.TrialFile)

		return Trial{}, fmt.Errorf("reload nginx: %w", err)
	}

	trial := Trial{Port: port, Previous: current, ExpiresAt: m.now().Add(m.cfg.Window).UTC()}
	if err := m.save(trial); err != nil {
		return Trial{}, err
	}

	return trial, nil
}

// Confirm makes the port being tried the panel's: start runs setup.sh for
// it, which writes every config for the port and ends the trial. The trial
// must not have ended (ErrTrialExpired).
func (m *Manager) Confirm(ctx context.Context, start func(ctx context.Context, port int) error) (Trial, error) {
	m.mu.Lock()
	defer m.mu.Unlock()

	trial, err := m.Pending()
	if err != nil {
		return Trial{}, err
	}

	if trial == nil {
		return Trial{}, ErrNoTrial
	}

	if trial.Confirmed {
		return *trial, nil
	}

	if m.now().After(trial.ExpiresAt) {
		if err := m.close(ctx); err != nil {
			return Trial{}, err
		}

		return Trial{}, fmt.Errorf("%w: %d", ErrTrialExpired, trial.Port)
	}

	if err := start(ctx, trial.Port); err != nil {
		return Trial{}, err
	}

	confirmedAt := m.now().UTC()
	trial.Confirmed, trial.ConfirmedAt = true, &confirmedAt

	if err := m.save(*trial); err != nil {
		return Trial{}, err
	}

	return *trial, nil
}

// Close stops serving the panel on the port being tried.
func (m *Manager) Close(ctx context.Context) error {
	m.mu.Lock()
	defer m.mu.Unlock()

	return m.close(ctx)
}

// Tick closes a trial that ended unconfirmed, or whose setup.sh run stopped
// without making the port the panel's; running reports whether setup.sh
// still runs. A trial setup.sh completed is forgotten.
func (m *Manager) Tick(ctx context.Context, running func(ctx context.Context) bool) error {
	m.mu.Lock()
	defer m.mu.Unlock()

	trial, err := m.Pending()
	if err != nil || trial == nil {
		return err
	}

	now := m.now()

	switch {
	case !trial.Confirmed && now.After(trial.ExpiresAt):
		m.logger().Info("panel port trial ended unconfirmed", "port", trial.Port)

		return m.close(ctx)
	case trial.Confirmed && trial.ConfirmedAt != nil && now.Sub(*trial.ConfirmedAt) > settle && !running(ctx):
		if m.Current() == trial.Port {
			// setup.sh made it the panel's port and removed the trial.
			return removeIfExists(m.cfg.StateFile)
		}

		m.logger().Warn("setup.sh stopped without moving the panel; closing the port tried", "port", trial.Port)

		return m.close(ctx)
	}

	return nil
}

// Watch runs Tick every interval until ctx ends.
func (m *Manager) Watch(ctx context.Context, interval time.Duration, running func(ctx context.Context) bool) {
	ticker := time.NewTicker(interval)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			tickCtx, cancel := context.WithTimeout(ctx, interval)
			if err := m.Tick(tickCtx, running); err != nil {
				m.logger().Error("panel port trial", "error", err)
			}
			cancel()
		}
	}
}

func (m *Manager) close(ctx context.Context) error {
	if err := removeIfExists(m.cfg.TrialFile); err != nil {
		return err
	}

	if _, err := m.cfg.Run(ctx, systemctlProgram, "reload", "nginx"); err != nil {
		return fmt.Errorf("reload nginx: %w", err)
	}

	return removeIfExists(m.cfg.StateFile)
}

func (m *Manager) save(trial Trial) error {
	content, err := json.Marshal(trial)
	if err != nil {
		return err
	}

	if err := os.WriteFile(m.cfg.StateFile, content, 0o600); err != nil {
		return fmt.Errorf("write %s: %w", m.cfg.StateFile, err)
	}

	return nil
}

func (m *Manager) free(port int) bool {
	if m.cfg.Free != nil {
		return m.cfg.Free(port)
	}

	listener, err := net.Listen("tcp", ":"+strconv.Itoa(port))
	if err != nil {
		return false
	}

	_ = listener.Close()

	return true
}

func (m *Manager) now() time.Time {
	if m.cfg.Now != nil {
		return m.cfg.Now()
	}

	return time.Now()
}

func (m *Manager) logger() *slog.Logger {
	if m.cfg.Logger != nil {
		return m.cfg.Logger
	}

	return slog.Default()
}

// retarget turns the panel's site on port from into one on port to: its
// listen directives, and the redirect of plain HTTP sent to the port.
func retarget(site []byte, from, to int) ([]byte, error) {
	listens := listenPattern.FindAllSubmatch(site, -1)
	if len(listens) == 0 {
		return nil, errors.New("the panel's site has no listen directive")
	}

	for _, listen := range listens {
		if string(listen[2]) != strconv.Itoa(from) {
			return nil, fmt.Errorf("the panel's site listens on %s, not on %d", listen[2], from)
		}
	}

	retargeted := listenPattern.ReplaceAll(site, []byte("${1}"+strconv.Itoa(to)+"${3}"))
	retargeted = []byte(strings.ReplaceAll(string(retargeted),
		fmt.Sprintf("$host:%d$request_uri", from), fmt.Sprintf("$host:%d$request_uri", to)))

	return append([]byte("# Panel on the port being tried. Written by rayxod; removed when the trial ends.\n"), retargeted...), nil
}

func removeIfExists(path string) error {
	if err := os.Remove(path); err != nil && !errors.Is(err, os.ErrNotExist) {
		return fmt.Errorf("remove %s: %w", path, err)
	}

	return nil
}
