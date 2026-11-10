package services

import (
	"context"
	"errors"
	"fmt"
	"slices"
	"strings"
	"testing"
	"time"
)

// fakeSystemd answers systemctl and the configuration tests like a server
// would, and records every call.
type fakeSystemd struct {
	states    map[string]map[string]string
	enabled   map[string]string
	pools     string
	failStart map[string]bool
	testFails bool
	calls     []string
}

func newFakeSystemd() *fakeSystemd {
	f := &fakeSystemd{states: map[string]map[string]string{}, enabled: map[string]string{}, failStart: map[string]bool{}}

	for _, service := range Catalog() {
		f.states[service.Unit] = map[string]string{"ActiveState": "active", "SubState": "running", "ConditionResult": "yes",
			"ActiveEnterTimestamp": "@1794148994", "MainPID": "1108", "MemoryCurrent": "20787200"}
	}

	return f
}

func (f *fakeSystemd) run(_ context.Context, program string, args ...string) ([]byte, error) {
	f.calls = append(f.calls, strings.TrimSpace(program+" "+strings.Join(args, " ")))

	if program != systemctlProgram {
		if f.testFails {
			return []byte("AH00526: Syntax error on line 3\nline two\nline three\nline four"), errors.New("exit status 1")
		}

		return nil, nil
	}

	command := args[0]
	if command == "--no-block" {
		command = args[1]
	}

	unit := args[len(args)-1]

	switch command {
	case "show":
		var out strings.Builder

		for _, unit := range args[4:] {
			state, ok := f.states[unit]
			if !ok {
				state = map[string]string{"ActiveState": "inactive", "ConditionResult": "yes"}
			}

			fmt.Fprintf(&out, "Id=%s\n", unit)
			for key, value := range state {
				fmt.Fprintf(&out, "%s=%s\n", key, value)
			}
			out.WriteString("\n")
		}

		return []byte(out.String()), nil
	case "is-enabled":
		state, ok := f.enabled[unit]
		if !ok {
			state = "enabled"
		}

		if state != "enabled" && !strings.HasSuffix(state, "\nenabled") {
			return []byte(state), errors.New("exit status 1")
		}

		return []byte(state), nil
	case "list-units":
		return []byte(f.pools), nil
	case "start":
		if f.failStart[unit] {
			return []byte("Job for " + unit + " failed.\nSee journalctl."), errors.New("exit status 1")
		}

		f.states[unit]["ActiveState"] = "active"
	}

	return nil, nil
}

func newManager(f *fakeSystemd, now *time.Time) *Manager {
	return New(Config{Run: f.run, Now: func() time.Time { return *now }})
}

func TestStatusReadsEveryServiceAndThePools(t *testing.T) {
	f := newFakeSystemd()
	f.states["postfix.service"]["MemoryCurrent"] = "[not set]"
	f.states["opendkim.service"]["ActiveState"] = "failed"
	f.enabled["pure-ftpd-mysql.service"] = "pure-ftpd-mysql.service is not a native service, redirecting to systemd-sysv-install.\nExecuting: /usr/lib/systemd/systemd-sysv-install is-enabled pure-ftpd-mysql\nenabled"
	f.enabled["cron.service"] = "disabled"
	f.pools = "rayxo-php84@budi.service loaded active running PHP 8.4\nrayxo-php83@sari.service loaded failed failed PHP 8.3\n"
	now := time.Now()

	report, err := newManager(f, &now).Status(context.Background())
	if err != nil {
		t.Fatal(err)
	}

	byUnit := map[string]Status{}
	for _, status := range report.Services {
		byUnit[status.Unit] = status
	}

	if len(report.Services) != len(Catalog()) || report.Services[0].Unit != "nginx.service" {
		t.Fatalf("services = %+v", report.Services)
	}

	if s := byUnit["nginx.service"]; s.Active != "active" || s.Since != 1794148994 || s.Memory != 20787200 || !s.Enabled || s.MainPID != 1108 {
		t.Fatalf("nginx = %+v", s)
	}

	if byUnit["postfix.service"].Memory != 0 || byUnit["opendkim.service"].Active != "failed" {
		t.Fatalf("postfix/opendkim = %+v / %+v", byUnit["postfix.service"], byUnit["opendkim.service"])
	}

	if !byUnit["pure-ftpd-mysql.service"].Enabled || byUnit["cron.service"].Enabled {
		t.Fatal("boot state read wrongly")
	}

	if report.Pools.Total != 2 || report.Pools.Running != 1 || !slices.Equal(report.Pools.Failed, []string{"rayxo-php83@sari.service"}) {
		t.Fatalf("pools = %+v", report.Pools)
	}
}

func TestActOnlyAllowsWhatIsSafe(t *testing.T) {
	now := time.Now()

	for _, tc := range []struct {
		unit, action, reason string
	}{
		{"sshd.service", ActionRestart, "unknown_service"},
		{"rayxod.service", ActionRestart, "not_allowed"},
		{"nginx.service", ActionStop, "not_allowed"},
		{"mariadb.service", ActionStop, "not_allowed"},
		{"cron.service", "kill", "not_allowed"},
	} {
		f := newFakeSystemd()

		err := newManager(f, &now).Act(context.Background(), tc.unit, tc.action)

		refused, ok := AsError(err)
		if !ok || refused.Reason != tc.reason {
			t.Fatalf("%s %s = %v, want %s", tc.action, tc.unit, err, tc.reason)
		}

		if len(f.calls) != 0 {
			t.Fatalf("%s %s ran %v", tc.action, tc.unit, f.calls)
		}
	}
}

func TestActTestsTheConfigurationAndKeepsThePanelReachable(t *testing.T) {
	now := time.Now()

	f := newFakeSystemd()
	f.testFails = true

	err := newManager(f, &now).Act(context.Background(), "nginx.service", ActionRestart)
	if refused, ok := AsError(err); !ok || refused.Reason != "config_invalid" || !strings.Contains(refused.Message, "Syntax error") || strings.Contains(refused.Message, "line four") {
		t.Fatalf("restart with a broken configuration = %v", err)
	}

	if slices.Contains(f.calls, systemctlProgram+" --no-block restart nginx.service") {
		t.Fatal("restarted despite the failed test")
	}

	for _, tc := range []struct {
		unit, action string
		want         []string
	}{
		{"nginx.service", ActionRestart, []string{nginxProgram + " -t", systemctlProgram + " --no-block restart nginx.service"}},
		{"apache2.service", ActionReload, []string{apachectlProgram + " configtest", systemctlProgram + " reload apache2.service"}},
		{"pure-ftpd-mysql.service", ActionStop, []string{systemctlProgram + " stop pure-ftpd-mysql.service", systemctlProgram + " disable pure-ftpd-mysql.service"}},
		{"pure-ftpd-mysql.service", ActionStart, []string{systemctlProgram + " reset-failed pure-ftpd-mysql.service", systemctlProgram + " enable pure-ftpd-mysql.service", systemctlProgram + " start pure-ftpd-mysql.service"}},
		{"php8.4-fpm.service", ActionRestart, []string{systemctlProgram + " --no-block restart php8.4-fpm.service"}},
	} {
		f := newFakeSystemd()

		if err := newManager(f, &now).Act(context.Background(), tc.unit, tc.action); err != nil {
			t.Fatalf("%s %s: %v", tc.action, tc.unit, err)
		}

		if !slices.Equal(f.calls, tc.want) {
			t.Fatalf("%s %s ran %v, want %v", tc.action, tc.unit, f.calls, tc.want)
		}
	}
}

func TestWatchdogStartsWhatShouldRunAndLeavesTheRestAlone(t *testing.T) {
	now := time.Date(2026, 11, 10, 12, 0, 0, 0, time.UTC)
	f := newFakeSystemd()
	f.states["pure-ftpd-mysql.service"]["ActiveState"] = "failed"
	f.states["cron.service"]["ActiveState"] = "inactive"
	f.enabled["cron.service"] = "disabled"
	f.pools = "rayxo-php84@budi.service loaded inactive dead PHP\nrayxo-php84@sari.service loaded failed failed PHP\n"
	f.states["rayxo-php84@budi.service"] = map[string]string{"ActiveState": "inactive", "ConditionResult": "no"}
	f.states["rayxo-php84@sari.service"] = map[string]string{"ActiveState": "failed", "ConditionResult": "yes"}

	m := newManager(f, &now)
	m.Check(context.Background())

	for _, want := range []string{"start pure-ftpd-mysql.service", "start rayxo-php84@sari.service"} {
		if !slices.Contains(f.calls, systemctlProgram+" "+want) {
			t.Fatalf("watchdog did not %s: %v", want, f.calls)
		}
	}

	for _, unwanted := range []string{"start cron.service", "start rayxo-php84@budi.service"} {
		if slices.Contains(f.calls, systemctlProgram+" "+unwanted) {
			t.Fatalf("watchdog ran %s", unwanted)
		}
	}

	events := m.Events()
	if len(events) != 2 || events[0].Outcome != "restarted" || events[1].Label != "Pure-FTPd" {
		t.Fatalf("events = %+v", events)
	}
}

func TestWatchdogGivesUpOnAServiceThatKeepsFailing(t *testing.T) {
	now := time.Date(2026, 11, 10, 12, 0, 0, 0, time.UTC)
	f := newFakeSystemd()
	f.failStart["opendkim.service"] = true
	m := newManager(f, &now)

	for range 6 {
		f.states["opendkim.service"]["ActiveState"] = "failed"
		m.Check(context.Background())
		now = now.Add(time.Minute)
	}

	starts := 0
	for _, call := range f.calls {
		if call == systemctlProgram+" start opendkim.service" {
			starts++
		}
	}

	var outcomes []string
	for _, event := range m.Events() {
		outcomes = append(outcomes, event.Outcome)
	}

	slices.Reverse(outcomes)

	if starts != maxRestarts || !slices.Equal(outcomes, []string{"restart_failed", "restart_failed", "restart_failed", "gave_up"}) {
		t.Fatalf("starts = %d, outcomes = %v", starts, outcomes)
	}

	// Once the window has passed, it tries again.
	now = now.Add(restartWindow)
	m.Check(context.Background())

	if outcome := m.Events()[0].Outcome; outcome != "restart_failed" {
		t.Fatalf("after the window = %s", outcome)
	}
}
