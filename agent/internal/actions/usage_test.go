package actions

import (
	"context"
	"encoding/json"
	"errors"
	"reflect"
	"slices"
	"testing"

	"rayxo/agent/internal/bandwidth"
	"rayxo/agent/internal/rpc"
)

type fakeCounter struct {
	got    bandwidth.Cursor
	report bandwidth.Report
	err    error
}

func (f *fakeCounter) Count(cursor bandwidth.Cursor) (bandwidth.Report, error) {
	f.got = cursor

	return f.report, f.err
}

func callUsage(t *testing.T, counter *fakeCounter, params any) (json.RawMessage, error) {
	t.Helper()

	raw, err := json.Marshal(params)
	if err != nil {
		t.Fatal(err)
	}

	result, err := usageMethods(counter)["usage.bandwidth"].Handler(context.Background(), raw)
	if err != nil {
		return nil, err
	}

	encoded, err := json.Marshal(result)
	if err != nil {
		t.Fatal(err)
	}

	return encoded, nil
}

func TestUsageMethodsAreRegisteredAndLockNothing(t *testing.T) {
	registry := rpc.NewRegistry()
	Register(registry, Config{})

	if !slices.Contains(registry.Names(), "usage.bandwidth") {
		t.Fatal("usage.bandwidth is not registered")
	}

	if usageMethods(&fakeCounter{})["usage.bandwidth"].LockKey != nil {
		t.Fatal("usage.bandwidth only reads, so it must not lock an account")
	}
}

func TestUsageBandwidthCountsFromTheCursorItIsGiven(t *testing.T) {
	counter := &fakeCounter{report: bandwidth.Report{
		Web:      []bandwidth.DomainBytes{{Domain: "example.com", Date: "2026-11-12", Bytes: 10}},
		Mail:     []bandwidth.DomainBytes{},
		FTP:      []bandwidth.UserBytes{},
		Cursor:   bandwidth.Cursor{"/var/log/mail.log": {Inode: 7, Offset: 99}},
		Complete: true,
	}}

	result, err := callUsage(t, counter, map[string]any{"cursor": map[string]any{"/var/log/mail.log": map[string]any{"inode": 7, "offset": 42}}})
	if err != nil {
		t.Fatalf("usage.bandwidth failed: %v", err)
	}

	if !reflect.DeepEqual(counter.got, bandwidth.Cursor{"/var/log/mail.log": {Inode: 7, Offset: 42}}) {
		t.Fatalf("the counter got %+v", counter.got)
	}

	want := `{"web":[{"domain":"example.com","date":"2026-11-12","bytes":10}],"mail":[],"ftp":[],"cursor":{"/var/log/mail.log":{"inode":7,"offset":99}},"complete":true}`
	if string(result) != want {
		t.Fatalf("unexpected result %s", result)
	}

	// The first count has no cursor.
	if _, err := callUsage(t, counter, map[string]any{}); err != nil || counter.got != nil {
		t.Fatalf("a count without a cursor: %v, %+v", err, counter.got)
	}
}

func TestUsageBandwidthRefusesUnknownParamsAndReportsFailures(t *testing.T) {
	_, err := callUsage(t, &fakeCounter{}, map[string]any{"path": "/etc/shadow"})
	assertCode(t, err, rpc.CodeInvalidParams)

	_, err = callUsage(t, &fakeCounter{err: errors.New("permission denied")}, map[string]any{})
	agentErr := assertCode(t, err, rpc.CodeActionFailed)

	if agentErr.Message != "count bandwidth: permission denied" {
		t.Fatalf("message %q", agentErr.Message)
	}
}
