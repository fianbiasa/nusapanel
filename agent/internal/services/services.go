// Package services shows and controls the system services rayxo relies on,
// from a fixed list, and keeps them running: the watchdog starts again
// every service that should run but does not.
package services

import (
	"bufio"
	"bytes"
	"context"
	"errors"
	"fmt"
	"slices"
	"strconv"
	"strings"
	"sync"
	"time"

	"rayxo/agent/internal/runner"
)

const (
	systemctlProgram = "/usr/bin/systemctl"
	nginxProgram     = "/usr/sbin/nginx"
	apachectlProgram = "/usr/sbin/apache2ctl"

	// PanelPHPVersion is the PHP version the panel itself runs on
	// (PHP_VERSION in installer/setup.sh).
	PanelPHPVersion = "8.4"

	// poolPattern matches the PHP-FPM pools of hosting accounts.
	poolPattern = "rayxo-php*@*.service"

	// maxEvents is how many watchdog events are kept.
	maxEvents = 50
	// maxRestarts restarts of one unit within restartWindow make the
	// watchdog give up on it until the window has passed.
	maxRestarts   = 3
	restartWindow = 15 * time.Minute
)

// Actions on a service.
const (
	ActionStart   = "start"
	ActionStop    = "stop"
	ActionRestart = "restart"
	ActionReload  = "reload"
)

// Service is one system service of the catalog.
type Service struct {
	Unit  string `json:"unit"`
	Label string `json:"label"`
	// CanStop is false for what the panel itself needs: stopping it would
	// lock the admin out.
	CanStop bool `json:"can_stop"`
	// CanControl is false for the agent itself.
	CanControl bool `json:"can_control"`
	// Test checks the service's configuration before a restart or reload.
	Test []string `json:"-"`
	// Panel marks services the panel's own request runs through; changing
	// them is not waited for, or the answer would never arrive.
	Panel bool `json:"-"`
}

// Catalog is every service the panel shows, in order.
func Catalog() []Service {
	return []Service{
		{Unit: "nginx.service", Label: "Nginx", CanControl: true, Panel: true, Test: []string{nginxProgram, "-t"}},
		{Unit: "apache2.service", Label: "Apache", CanControl: true, CanStop: true, Test: []string{apachectlProgram, "configtest"}},
		{Unit: "php" + PanelPHPVersion + "-fpm.service", Label: "PHP-FPM (panel & phpMyAdmin)", CanControl: true, Panel: true},
		{Unit: "mariadb.service", Label: "MariaDB", CanControl: true, Panel: true},
		{Unit: "postfix.service", Label: "Postfix", CanControl: true, CanStop: true},
		{Unit: "opendkim.service", Label: "OpenDKIM", CanControl: true, CanStop: true},
		// Postfix asks it about every recipient: while it is down, mail
		// waits (451) instead of going anywhere.
		{Unit: "rayxo-srs.service", Label: "SRS (email diteruskan)", CanControl: true, CanStop: true},
		{Unit: "pure-ftpd-mysql.service", Label: "Pure-FTPd", CanControl: true, CanStop: true},
		{Unit: "cron.service", Label: "Cron", CanControl: true, CanStop: true},
		{Unit: "rayxo-queue.service", Label: "Queue worker panel", CanControl: true, Panel: true},
		{Unit: "rayxo-backup-queue.service", Label: "Worker backup", CanControl: true},
		{Unit: "rayxod.service", Label: "Agent rayxod"},
	}
}

// Status is the state of one service.
type Status struct {
	Service
	// Active is systemd's ActiveState: active, inactive, failed,
	// activating, deactivating or reloading.
	Active string `json:"active"`
	Sub    string `json:"sub"`
	// Enabled is whether the service starts at boot.
	Enabled bool `json:"enabled"`
	// Since is when the service last became active (Unix seconds), or 0.
	Since int64 `json:"since"`
	// Memory is in bytes, or 0 when systemd does not know.
	Memory  uint64 `json:"memory"`
	MainPID int    `json:"main_pid"`
}

// Pools sums up the PHP-FPM pools of hosting accounts.
type Pools struct {
	Total   int      `json:"total"`
	Running int      `json:"running"`
	Failed  []string `json:"failed"`
}

// Event is something the watchdog did.
type Event struct {
	Time    time.Time `json:"time"`
	Unit    string    `json:"unit"`
	Label   string    `json:"label"`
	Outcome string    `json:"outcome"` // restarted, restart_failed or gave_up
	Message string    `json:"message"`
}

// Report is what service.status answers.
type Report struct {
	Services []Status `json:"services"`
	Pools    Pools    `json:"pools"`
	Events   []Event  `json:"events"`
}

// Error is a refused action the panel can explain: Reason is
// unknown_service, not_allowed or config_invalid.
type Error struct {
	Reason  string
	Message string
}

func (e *Error) Error() string { return e.Message }

// Config is what a Manager runs programs with.
type Config struct {
	Run func(ctx context.Context, program string, args ...string) ([]byte, error)
	Now func() time.Time
}

// DefaultConfig runs real programs.
func DefaultConfig() Config {
	return Config{Run: runner.Run, Now: time.Now}
}

// Manager shows and controls the services of the catalog, and runs the
// watchdog.
type Manager struct {
	cfg     Config
	catalog []Service

	mu       sync.Mutex
	events   []Event
	restarts map[string][]time.Time
	gaveUp   map[string]time.Time
}

// New returns a Manager for the catalog.
func New(cfg Config) *Manager {
	return &Manager{cfg: cfg, catalog: Catalog(), restarts: map[string][]time.Time{}, gaveUp: map[string]time.Time{}}
}

// Status reports every service of the catalog, the account pools and the
// latest watchdog events (newest first).
func (m *Manager) Status(ctx context.Context) (Report, error) {
	units := make([]string, len(m.catalog))
	for i, service := range m.catalog {
		units[i] = service.Unit
	}

	states, err := m.show(ctx, units...)
	if err != nil {
		return Report{}, err
	}

	report := Report{Services: make([]Status, 0, len(m.catalog))}

	for _, service := range m.catalog {
		state := states[service.Unit]
		status := Status{
			Service: service,
			Active:  state["ActiveState"],
			Sub:     state["SubState"],
			Enabled: m.enabled(ctx, service.Unit),
			Since:   unixTime(state["ActiveEnterTimestamp"]),
			MainPID: atoi(state["MainPID"]),
		}

		if memory, err := strconv.ParseUint(state["MemoryCurrent"], 10, 64); err == nil && memory < 1<<62 {
			status.Memory = memory
		}

		report.Services = append(report.Services, status)
	}

	report.Pools, err = m.pools(ctx)
	if err != nil {
		return Report{}, err
	}

	report.Events = m.Events()

	return report, nil
}

// Act runs action on unit. A restart or reload of a service with a
// configuration test runs the test first. Stopping also disables the
// service, so neither the watchdog nor a reboot starts it again; starting
// enables it.
func (m *Manager) Act(ctx context.Context, unit, action string) error {
	index := slices.IndexFunc(m.catalog, func(s Service) bool { return s.Unit == unit })
	if index < 0 {
		return &Error{Reason: "unknown_service", Message: fmt.Sprintf("%s is not a service rayxo manages", unit)}
	}

	service := m.catalog[index]

	switch {
	case !service.CanControl:
		return &Error{Reason: "not_allowed", Message: fmt.Sprintf("%s cannot be controlled from the panel", service.Label)}
	case !slices.Contains([]string{ActionStart, ActionStop, ActionRestart, ActionReload}, action):
		return &Error{Reason: "not_allowed", Message: fmt.Sprintf("unknown action %q", action)}
	case action == ActionStop && !service.CanStop:
		return &Error{Reason: "not_allowed", Message: fmt.Sprintf("%s is needed by the panel itself and cannot be stopped", service.Label)}
	}

	if (action == ActionRestart || action == ActionReload) && len(service.Test) > 0 {
		if output, err := m.cfg.Run(ctx, service.Test[0], service.Test[1:]...); err != nil {
			return &Error{Reason: "config_invalid", Message: fmt.Sprintf("%s refuses its configuration: %s", service.Label, firstLines(output, 3))}
		}
	}

	change := []string{action, unit}
	if service.Panel {
		// The request that asked for this runs through the service.
		change = append([]string{"--no-block"}, change...)
	}

	// Separate calls: "disable --now" leaves SysV services (Pure-FTPd)
	// running.
	commands := [][]string{change}

	switch action {
	case ActionStart:
		_, _ = m.cfg.Run(ctx, systemctlProgram, "reset-failed", unit)
		commands = [][]string{{"enable", unit}, change}
	case ActionStop:
		commands = [][]string{change, {"disable", unit}}
	}

	for _, args := range commands {
		if output, err := m.cfg.Run(ctx, systemctlProgram, args...); err != nil {
			return fmt.Errorf("systemctl %s: %w: %s", strings.Join(args, " "), err, firstLines(output, 3))
		}
	}

	// A service started or restarted by hand gets a fresh chance.
	m.mu.Lock()
	delete(m.restarts, unit)
	delete(m.gaveUp, unit)
	m.mu.Unlock()

	return nil
}

// Events returns the latest watchdog events, newest first.
func (m *Manager) Events() []Event {
	m.mu.Lock()
	defer m.mu.Unlock()

	events := slices.Clone(m.events)
	slices.Reverse(events)

	if events == nil {
		events = []Event{}
	}

	return events
}

// show reads the state of units, by unit.
func (m *Manager) show(ctx context.Context, units ...string) (map[string]map[string]string, error) {
	args := append([]string{"show", "--timestamp=unix", "-p", "Id,ActiveState,SubState,ActiveEnterTimestamp,MainPID,MemoryCurrent,ConditionResult"}, units...)

	output, err := m.cfg.Run(ctx, systemctlProgram, args...)
	if err != nil {
		return nil, fmt.Errorf("systemctl show: %w", err)
	}

	states := map[string]map[string]string{}

	for block := range strings.SplitSeq(string(output), "\n\n") {
		state := map[string]string{}

		scanner := bufio.NewScanner(strings.NewReader(block))
		for scanner.Scan() {
			if key, value, ok := strings.Cut(scanner.Text(), "="); ok {
				state[key] = value
			}
		}

		if id := state["Id"]; id != "" {
			states[id] = state
		}
	}

	return states, nil
}

// enabled reports whether unit starts at boot. SysV services (Pure-FTPd)
// report "generated" as their unit file state, so is-enabled is asked; its
// answer is on the last line.
func (m *Manager) enabled(ctx context.Context, unit string) bool {
	output, _ := m.cfg.Run(ctx, systemctlProgram, "is-enabled", unit)

	lines := strings.Split(strings.TrimSpace(string(output)), "\n")
	state := strings.TrimSpace(lines[len(lines)-1])

	return slices.Contains([]string{"enabled", "enabled-runtime", "static", "generated", "alias", "indirect"}, state)
}

// pools sums up the account pools.
func (m *Manager) pools(ctx context.Context) (Pools, error) {
	output, err := m.cfg.Run(ctx, systemctlProgram, "list-units", "--all", "--plain", "--no-legend", poolPattern)
	if err != nil {
		return Pools{}, fmt.Errorf("systemctl list-units: %w", err)
	}

	pools := Pools{Failed: []string{}}

	scanner := bufio.NewScanner(bytes.NewReader(output))
	for scanner.Scan() {
		fields := strings.Fields(scanner.Text())
		if len(fields) < 4 {
			continue
		}

		pools.Total++

		switch fields[2] {
		case "active":
			pools.Running++
		case "failed":
			pools.Failed = append(pools.Failed, fields[0])
		}
	}

	return pools, nil
}

// Watch runs the watchdog every interval until ctx ends.
func (m *Manager) Watch(ctx context.Context, interval time.Duration) {
	ticker := time.NewTicker(interval)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			checkCtx, cancel := context.WithTimeout(ctx, interval)
			m.Check(checkCtx)
			cancel()
		}
	}
}

// Check starts again every service of the catalog and every account pool
// that should run but does not: it starts at boot, is inactive or failed,
// and its start conditions hold (the pool of a suspended account does
// not). A unit restarted too often is left alone for a while.
func (m *Manager) Check(ctx context.Context) {
	units := []string{}
	labels := map[string]string{}

	for _, service := range m.catalog {
		if service.CanControl {
			units = append(units, service.Unit)
			labels[service.Unit] = service.Label
		}
	}

	if pools, err := m.poolUnits(ctx); err == nil {
		for _, unit := range pools {
			units = append(units, unit)
			labels[unit] = "PHP pool " + strings.TrimSuffix(unit, ".service")
		}
	}

	states, err := m.show(ctx, units...)
	if err != nil {
		return
	}

	for _, unit := range units {
		state := states[unit]
		active := state["ActiveState"]

		if (active != "inactive" && active != "failed") || state["ConditionResult"] == "no" || !m.enabled(ctx, unit) {
			continue
		}

		m.restart(ctx, unit, labels[unit])
	}
}

func (m *Manager) poolUnits(ctx context.Context) ([]string, error) {
	output, err := m.cfg.Run(ctx, systemctlProgram, "list-units", "--all", "--plain", "--no-legend", poolPattern)
	if err != nil {
		return nil, err
	}

	units := []string{}

	scanner := bufio.NewScanner(bytes.NewReader(output))
	for scanner.Scan() {
		if fields := strings.Fields(scanner.Text()); len(fields) > 0 {
			units = append(units, fields[0])
		}
	}

	return units, nil
}

// restart starts unit again, unless it was restarted maxRestarts times
// within restartWindow: then the watchdog says once that it gives up, and
// leaves the unit alone until the window has passed.
func (m *Manager) restart(ctx context.Context, unit, label string) {
	now := m.cfg.Now()

	m.mu.Lock()
	recent := slices.DeleteFunc(m.restarts[unit], func(at time.Time) bool { return now.Sub(at) > restartWindow })
	m.restarts[unit] = recent
	tooOften := len(recent) >= maxRestarts

	_, alreadyGaveUp := m.gaveUp[unit]
	if tooOften && !alreadyGaveUp {
		m.gaveUp[unit] = now
	}

	if !tooOften {
		delete(m.gaveUp, unit)
		m.restarts[unit] = append(recent, now)
	}
	m.mu.Unlock()

	if tooOften {
		if !alreadyGaveUp {
			m.record(Event{Time: now, Unit: unit, Label: label, Outcome: "gave_up",
				Message: fmt.Sprintf("restarted %d times in %d minutes; left alone for now", maxRestarts, int(restartWindow.Minutes()))})
		}

		return
	}

	_, _ = m.cfg.Run(ctx, systemctlProgram, "reset-failed", unit)

	if output, err := m.cfg.Run(ctx, systemctlProgram, "start", unit); err != nil {
		m.record(Event{Time: now, Unit: unit, Label: label, Outcome: "restart_failed", Message: firstLines(output, 2)})

		return
	}

	m.record(Event{Time: now, Unit: unit, Label: label, Outcome: "restarted", Message: "was not running; started again"})
}

func (m *Manager) record(event Event) {
	m.mu.Lock()
	defer m.mu.Unlock()

	m.events = append(m.events, event)
	if len(m.events) > maxEvents {
		m.events = m.events[len(m.events)-maxEvents:]
	}
}

// AsError reports whether err is a refused action, and returns it.
func AsError(err error) (*Error, bool) {
	var refused *Error
	ok := errors.As(err, &refused)

	return refused, ok
}

func unixTime(value string) int64 {
	seconds, err := strconv.ParseInt(strings.TrimPrefix(value, "@"), 10, 64)
	if err != nil {
		return 0
	}

	return seconds
}

func atoi(value string) int {
	number, _ := strconv.Atoi(value)

	return number
}

func firstLines(output []byte, count int) string {
	lines := strings.Split(strings.TrimSpace(string(output)), "\n")
	if len(lines) > count {
		lines = lines[:count]
	}

	return strings.Join(lines, " ")
}
