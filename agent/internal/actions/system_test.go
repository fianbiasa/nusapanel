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
	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/webserver"
)

// fakeHostname plays the server's certificate and clock.
type fakeHostname struct {
	issueErr  error
	statusErr error
	timezone  string
	setErr    error
	issued    int
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

	result, err := systemMethods(fake, fake)[method].Handler(context.Background(), raw)
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

	for _, name := range []string{"system.settings", "system.set_timezone", "ssl.issue_hostname"} {
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
