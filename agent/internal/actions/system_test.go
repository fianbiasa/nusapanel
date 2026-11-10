package actions

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"slices"
	"strings"
	"testing"
	"time"

	"rayxo/agent/internal/clock"
	"rayxo/agent/internal/panelport"
	"rayxo/agent/internal/reconfigure"
	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/webserver"
)

// fakeHostname plays the server's certificate, clock and setup.sh.
type fakeHostname struct {
	issueErr  error
	statusErr error
	timezone  string
	setErr    error
	issued    int
	websites  []string
	startErr  error
	started   []string
	state     *reconfigure.State
	ports     []reconfigure.Request
	trial     *panelport.Trial
	openErr   error
	closed    int
}

func (f *fakeHostname) Current() int { return 2222 }

func (f *fakeHostname) Pending() (*panelport.Trial, error) { return f.trial, nil }

func (f *fakeHostname) Open(_ context.Context, port int) (panelport.Trial, error) {
	if f.openErr != nil {
		return panelport.Trial{}, f.openErr
	}

	f.trial = &panelport.Trial{Port: port, Previous: 2222}

	return *f.trial, nil
}

func (f *fakeHostname) Confirm(ctx context.Context, start func(ctx context.Context, port int) error) (panelport.Trial, error) {
	if f.trial == nil {
		return panelport.Trial{}, panelport.ErrNoTrial
	}

	if err := start(ctx, f.trial.Port); err != nil {
		return panelport.Trial{}, err
	}

	f.trial.Confirmed = true

	return *f.trial, nil
}

func (f *fakeHostname) Close(context.Context) error {
	f.closed++
	f.trial = nil

	return nil
}

func (f *fakeHostname) HasWebsite(domain string) (bool, error) {
	return slices.Contains(f.websites, domain), nil
}

func (f *fakeHostname) Start(_ context.Context, request reconfigure.Request) error {
	if f.startErr != nil {
		return f.startErr
	}

	f.started = append(f.started, request.Hostname)
	f.ports = append(f.ports, request)

	return nil
}

func (f *fakeHostname) Status(context.Context) (*reconfigure.State, error) {
	return f.state, nil
}

func (f *fakeHostname) ServerHostname() (string, error) {
	return "server1.example.net", nil
}

func (f *fakeHostname) HostnameCertificateStatus() (webserver.HostnameCertificate, error) {
	return webserver.HostnameCertificate{
		Hostname: "server1.example.net", Names: []string{"server1.example.net"}, Issuer: "Let's Encrypt",
		ExpiresAt: time.Date(2027, 2, 5, 0, 0, 0, 0, time.UTC), LetsEncrypt: true,
	}, f.statusErr
}

func (f *fakeHostname) IssueHostnameCertificate(context.Context) (webserver.HostnameCertificate, error) {
	f.issued++

	if f.issueErr != nil {
		return webserver.HostnameCertificate{}, f.issueErr
	}

	return f.HostnameCertificateStatus()
}

func (f *fakeHostname) Timezone() string { return f.timezone }

func (f *fakeHostname) SetTimezone(_ context.Context, timezone string) error {
	if f.setErr != nil {
		return f.setErr
	}

	f.timezone = timezone

	return nil
}

func callSystem(t *testing.T, fake *fakeHostname, method string, params any) (map[string]any, error) {
	t.Helper()

	raw, _ := json.Marshal(params)

	result, err := systemMethods(fake, fake, fake, fake)[method].Handler(context.Background(), raw)
	if err != nil {
		return nil, err
	}

	encoded, _ := json.Marshal(result)

	var decoded map[string]any
	if err := json.Unmarshal(encoded, &decoded); err != nil {
		t.Fatal(err)
	}

	return decoded, nil
}

func TestSystemMethodsAreRegistered(t *testing.T) {
	registry := rpc.NewRegistry()
	Register(registry, Config{})

	for _, name := range []string{"system.settings", "system.set_timezone", "ssl.issue_hostname", "system.change_hostname", "panel.port_trial", "panel.port_confirm", "panel.port_cancel"} {
		if !slices.Contains(registry.Names(), name) {
			t.Errorf("%s is not registered", name)
		}
	}
}

func TestSystemSettingsReportHostnameTimezoneAndCertificate(t *testing.T) {
	fake := &fakeHostname{timezone: "Asia/Jakarta"}

	settings, err := callSystem(t, fake, "system.settings", map[string]any{})
	if err != nil {
		t.Fatal(err)
	}

	certificate, _ := settings["certificate"].(map[string]any)
	if settings["hostname"] != "server1.example.net" || settings["timezone"] != "Asia/Jakarta" ||
		certificate["lets_encrypt"] != true || certificate["expires_at"] != "2027-02-05T00:00:00Z" {
		t.Fatalf("unexpected settings %v", settings)
	}

	// An unreadable certificate does not hide the other settings.
	fake.statusErr = errors.New("read panel.crt: no such file")

	settings, err = callSystem(t, fake, "system.settings", map[string]any{})
	if err != nil || settings["certificate"] != nil || settings["timezone"] != "Asia/Jakarta" {
		t.Fatalf("settings = %v, %v; want the timezone and no certificate", settings, err)
	}
}

func TestSetTimezoneExplainsAnUnknownName(t *testing.T) {
	fake := &fakeHostname{timezone: "UTC"}

	result, err := callSystem(t, fake, "system.set_timezone", map[string]any{"timezone": "Asia/Makassar"})
	if err != nil || result["timezone"] != "Asia/Makassar" {
		t.Fatalf("result = %v, %v", result, err)
	}

	fake.setErr = fmt.Errorf("%w: %q", clock.ErrUnknownTimezone, "Mars/Olympus")

	_, err = callSystem(t, fake, "system.set_timezone", map[string]any{"timezone": "Mars/Olympus"})

	var rpcErr *rpc.Error
	if !errors.As(err, &rpcErr) || rpcErr.Code != rpc.CodeInvalidParams || !strings.Contains(rpcErr.Message, "Mars/Olympus") {
		t.Fatalf("err = %v, want invalid params naming the timezone", err)
	}
}

func TestIssueHostnameCertificateExplainsFailures(t *testing.T) {
	for _, tc := range []struct {
		err  error
		want string
	}{
		{&webserver.UnreachableError{Domain: "server1.example.net", Reason: "no DNS record"}, "server1.example.net does not reach this server over HTTP (no DNS record)"},
		{&webserver.IssueError{Domain: "server1.example.net", RateLimited: true}, "Let's Encrypt rate limit reached for server1.example.net"},
		{webserver.ErrCertbotBusy, "another certificate operation is running"},
		{fmt.Errorf("%w: %q", webserver.ErrNoServerHostname, "localhost"), "no fully qualified hostname"},
	} {
		_, err := callSystem(t, &fakeHostname{issueErr: tc.err}, "ssl.issue_hostname", map[string]any{})

		var rpcErr *rpc.Error
		if !errors.As(err, &rpcErr) || rpcErr.Code != rpc.CodeActionFailed || !strings.Contains(rpcErr.Message, tc.want) {
			t.Errorf("%v: err = %v, want a message containing %q", tc.err, err, tc.want)
		}
	}

	result, err := callSystem(t, &fakeHostname{}, "ssl.issue_hostname", map[string]any{})
	if err != nil || result["lets_encrypt"] != true {
		t.Fatalf("result = %v, %v", result, err)
	}
}

func TestChangeHostnameStartsSetupForAFreeName(t *testing.T) {
	fake := &fakeHostname{websites: []string{"shop.example.com"}}

	for _, tc := range []struct {
		hostname string
		code     int
		want     string
	}{
		{"not a hostname", rpc.CodeInvalidParams, "invalid params"},
		{"Server2.Example.net", rpc.CodeInvalidParams, "invalid params"},
		{"server1.example.net", rpc.CodeActionFailed, "already the hostname"},
		{"shop.example.com", rpc.CodeActionFailed, "is the domain of a website"},
	} {
		_, err := callSystem(t, fake, "system.change_hostname", map[string]any{"hostname": tc.hostname})

		var rpcErr *rpc.Error
		if !errors.As(err, &rpcErr) || rpcErr.Code != tc.code || !strings.Contains(rpcErr.Message, tc.want) {
			t.Errorf("%q: err = %v, want code %d containing %q", tc.hostname, err, tc.code, tc.want)
		}
	}

	if len(fake.started) != 0 {
		t.Fatalf("setup.sh started for a refused name: %q", fake.started)
	}

	result, err := callSystem(t, fake, "system.change_hostname", map[string]any{"hostname": "server2.example.net"})
	if err != nil || result["hostname"] != "server2.example.net" || !slices.Equal(fake.started, []string{"server2.example.net"}) {
		t.Fatalf("result = %v, %v; started %q", result, err, fake.started)
	}

	fake.startErr = reconfigure.ErrRunning
	_, err = callSystem(t, fake, "system.change_hostname", map[string]any{"hostname": "server3.example.net"})

	var rpcErr *rpc.Error
	if !errors.As(err, &rpcErr) || rpcErr.Data == nil || rpcErr.Data.(map[string]string)["reason"] != "busy" {
		t.Fatalf("err = %v, want reason busy", err)
	}
}

func TestSystemSettingsReportTheLatestHostnameChange(t *testing.T) {
	code := 1
	fake := &fakeHostname{timezone: "UTC", state: &reconfigure.State{Request: reconfigure.Request{Hostname: "server2.example.net"}, ExitCode: &code, Error: "A record belum ada", Log: []string{"GAGAL: A record belum ada"}}}

	settings, err := callSystem(t, fake, "system.settings", map[string]any{})
	if err != nil {
		t.Fatal(err)
	}

	change, _ := settings["reconfigure"].(map[string]any)
	if change["hostname"] != "server2.example.net" || change["exit_code"] != float64(1) || change["error"] != "A record belum ada" {
		t.Fatalf("reconfigure = %v", settings["reconfigure"])
	}
}

func TestPanelPortIsTriedConfirmedThroughSetupAndCancelled(t *testing.T) {
	fake := &fakeHostname{}

	trial, err := callSystem(t, fake, "panel.port_trial", map[string]any{"port": 8443})
	if err != nil || trial["port"] != float64(8443) {
		t.Fatalf("trial = %v, %v", trial, err)
	}

	settings, _ := callSystem(t, fake, "system.settings", map[string]any{})
	if settings["panel_port"] != float64(2222) || settings["port_trial"].(map[string]any)["port"] != float64(8443) {
		t.Fatalf("settings = %v", settings)
	}

	if _, err := callSystem(t, fake, "panel.port_confirm", map[string]any{}); err != nil {
		t.Fatal(err)
	}

	if len(fake.ports) != 1 || fake.ports[0] != (reconfigure.Request{PanelPort: 8443}) {
		t.Fatalf("setup.sh started with %+v, want only the panel port", fake.ports)
	}

	if _, err := callSystem(t, fake, "panel.port_cancel", map[string]any{}); err != nil || fake.closed != 1 {
		t.Fatalf("cancel: %v, closed %d", err, fake.closed)
	}
}

func TestPanelPortRefusalsAreExplained(t *testing.T) {
	for _, tc := range []struct {
		err    error
		code   int
		want   string
		reason string
	}{
		{panelport.ErrReservedPort, rpc.CodeInvalidParams, "port 3306 is below 1024 or used by another service", ""},
		{panelport.ErrPortInUse, rpc.CodeActionFailed, "port 3306 is already in use", ""},
		{panelport.ErrTrialRunning, rpc.CodeActionFailed, "another port is being tried", "busy"},
	} {
		_, err := callSystem(t, &fakeHostname{openErr: tc.err}, "panel.port_trial", map[string]any{"port": 3306})

		var rpcErr *rpc.Error
		if !errors.As(err, &rpcErr) || rpcErr.Code != tc.code || !strings.Contains(rpcErr.Message, tc.want) {
			t.Errorf("%v: err = %v, want %q", tc.err, err, tc.want)
		}

		if tc.reason != "" && (rpcErr == nil || rpcErr.Data == nil || rpcErr.Data.(map[string]string)["reason"] != tc.reason) {
			t.Errorf("%v: no reason %q", tc.err, tc.reason)
		}
	}

	_, err := callSystem(t, &fakeHostname{}, "panel.port_confirm", map[string]any{})

	var rpcErr *rpc.Error
	if !errors.As(err, &rpcErr) || !strings.Contains(rpcErr.Message, "no port is being tried") {
		t.Fatalf("confirm without a trial: err = %v", err)
	}
}
