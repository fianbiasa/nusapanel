package actions

import (
	"context"
	"encoding/json"
	"fmt"
	"slices"
	"strings"
	"testing"

	"nusapanel/agent/internal/database"
	"nusapanel/agent/internal/rpc"
)

// fakeDatabaseServer records the db.* calls that reach MariaDB.
type fakeDatabaseServer struct {
	calls []string
	fail  error
	sizes []database.Size
}

func (f *fakeDatabaseServer) CreateDatabase(_ context.Context, user, name string) error {
	f.calls = append(f.calls, "create "+user+" "+name)

	return f.fail
}

func (f *fakeDatabaseServer) DeleteDatabase(_ context.Context, user, name string) error {
	f.calls = append(f.calls, "drop "+user+" "+name)

	return f.fail
}

func (f *fakeDatabaseServer) ApplyUser(_ context.Context, user, name, hash string, databases []string) error {
	f.calls = append(f.calls, "user "+user+" "+name+" "+hash+" ["+strings.Join(databases, ",")+"]")

	return f.fail
}

func (f *fakeDatabaseServer) DeleteUser(_ context.Context, user, name string) error {
	f.calls = append(f.calls, "drop user "+user+" "+name)

	return f.fail
}

func (f *fakeDatabaseServer) ApplyOwner(_ context.Context, user, hash string, databases []string) error {
	f.calls = append(f.calls, "owner "+user+" "+hash+" ["+strings.Join(databases, ",")+"]")

	return f.fail
}

func (f *fakeDatabaseServer) CloseOwner(_ context.Context, user string) error {
	f.calls = append(f.calls, "close owner "+user)

	return f.fail
}

func (f *fakeDatabaseServer) Report(context.Context) ([]database.Size, error) {
	f.calls = append(f.calls, "report")

	return f.sizes, f.fail
}

const testHash = "*6BB4837EB74329105EE4568DDA7DC67ED2CA2AD9"

func callDatabase(t *testing.T, system *fakeSystem, server *fakeDatabaseServer, method string, params any) (json.RawMessage, error) {
	t.Helper()

	raw, err := json.Marshal(params)
	if err != nil {
		t.Fatal(err)
	}

	registered, ok := databaseMethods(system, server)[method]
	if !ok {
		t.Fatalf("unknown method %s", method)
	}

	if registered.LockKey != nil {
		key, err := registered.LockKey(raw)
		if err != nil {
			return nil, err
		}

		if key != "account:budi" {
			t.Fatalf("%s locks %q", method, key)
		}
	}

	result, err := registered.Handler(context.Background(), raw)
	if err != nil {
		return nil, err
	}

	encoded, _ := json.Marshal(result)

	return encoded, nil
}

func TestDatabaseMethodsAreRegistered(t *testing.T) {
	registry := rpc.NewRegistry()
	Register(registry)

	for _, name := range []string{"db.create_database", "db.delete_database", "db.apply_user", "db.delete_user", "db.open_sso", "db.close_sso", "db.report"} {
		if !slices.Contains(registry.Names(), name) {
			t.Errorf("method %s is not registered", name)
		}
	}

	if databaseMethods(newFakeSystem(), &fakeDatabaseServer{})["db.report"].LockKey != nil {
		t.Fatal("db.report only reads, but locks")
	}
}

func TestDatabaseMethodsReachMariaDB(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 1001)
	server := &fakeDatabaseServer{}

	calls := []struct {
		method string
		params map[string]any
	}{
		{"db.create_database", map[string]any{"username": "budi", "database": "budi_shop"}},
		{"db.apply_user", map[string]any{"username": "budi", "user": "budi_wp", "password_hash": testHash, "databases": []string{"budi_shop"}}},
		{"db.delete_user", map[string]any{"username": "budi", "user": "budi_wp"}},
		{"db.open_sso", map[string]any{"username": "budi", "password_hash": testHash, "databases": []string{"budi_shop", "budi_blog"}}},
		{"db.close_sso", map[string]any{"username": "budi"}},
		{"db.delete_database", map[string]any{"username": "budi", "database": "budi_shop"}},
	}

	for _, call := range calls {
		if result, err := callDatabase(t, system, server, call.method, call.params); err != nil || string(result) != `{}` {
			t.Fatalf("%s: %s, %v", call.method, result, err)
		}
	}

	want := []string{
		"create budi budi_shop",
		"user budi budi_wp " + testHash + " [budi_shop]",
		"drop user budi budi_wp",
		"owner budi " + testHash + " [budi_shop,budi_blog]",
		"close owner budi",
		"drop budi budi_shop",
	}

	if !slices.Equal(server.calls, want) {
		t.Fatalf("calls = %q", server.calls)
	}
}

func TestDatabaseMethodsRefuseWhatIsNotTheAccounts(t *testing.T) {
	invalid := map[string][]map[string]any{
		"db.create_database": {
			{"username": "budi", "database": "sari_shop"},
			{"username": "budi", "database": "mysql"},
			{"username": "budi", "database": "budi_shop`; DROP DATABASE mysql; --"},
			{"username": "root", "database": "root_x"},
			{"username": "budi", "database": "budi_shop", "charset": "latin1"},
		},
		"db.apply_user": {
			{"username": "budi", "user": "budi_wp", "password_hash": "secret"},
			{"username": "budi", "user": "budi_wp", "password_hash": testHash, "databases": []string{"sari_shop"}},
			{"username": "budi", "user": "root", "password_hash": testHash},
			{"username": "budi", "user": "budi_wp", "password": "secret", "password_hash": testHash},
		},
		"db.delete_user":     {{"username": "budi", "user": "sari_wp"}},
		"db.delete_database": {{"username": "budi", "database": "nusapanel"}},
		"db.open_sso": {
			{"username": "budi", "password_hash": "secret", "databases": []string{"budi_shop"}},
			{"username": "budi", "password_hash": testHash, "databases": []string{"sari_shop"}},
			{"username": "budi", "password_hash": testHash, "databases": []string{"mysql"}},
			{"username": "root", "password_hash": testHash},
			{"username": "budi", "password": "secret", "password_hash": testHash},
		},
		"db.close_sso": {{"username": "nusapanel"}, {"username": "Budi"}},
		"db.report":    {{"username": "budi"}},
	}

	for method, cases := range invalid {
		for _, params := range cases {
			system := newFakeSystem()
			system.addAccount("budi", 1001)
			server := &fakeDatabaseServer{}

			_, err := callDatabase(t, system, server, method, params)

			assertCode(t, err, rpc.CodeInvalidParams)

			if len(server.calls) != 0 {
				t.Fatalf("%s %v reached MariaDB", method, params)
			}
		}
	}
}

func TestCreatingNeedsTheAccountButDeletingDoesNot(t *testing.T) {
	system := newFakeSystem()
	server := &fakeDatabaseServer{}

	_, err := callDatabase(t, system, server, "db.create_database", map[string]any{"username": "budi", "database": "budi_shop"})
	if agentErr := assertCode(t, err, rpc.CodeActionFailed); agentErr.Message != "account budi does not exist" {
		t.Fatalf("message = %q", agentErr.Message)
	}

	if _, err := callDatabase(t, system, server, "db.delete_database", map[string]any{"username": "budi", "database": "budi_shop"}); err != nil {
		t.Fatalf("delete failed: %v", err)
	}

	if !slices.Equal(server.calls, []string{"drop budi budi_shop"}) {
		t.Fatalf("calls = %q", server.calls)
	}
}

func TestOpeningPhpMyAdminNeedsTheAccountButClosingDoesNot(t *testing.T) {
	system := newFakeSystem()
	server := &fakeDatabaseServer{}

	_, err := callDatabase(t, system, server, "db.open_sso", map[string]any{"username": "budi", "password_hash": testHash, "databases": []string{}})
	if agentErr := assertCode(t, err, rpc.CodeActionFailed); agentErr.Message != "account budi does not exist" {
		t.Fatalf("message = %q", agentErr.Message)
	}

	if _, err := callDatabase(t, system, server, "db.close_sso", map[string]any{"username": "budi"}); err != nil {
		t.Fatalf("close failed: %v", err)
	}

	if !slices.Equal(server.calls, []string{"close owner budi"}) {
		t.Fatalf("calls = %q", server.calls)
	}
}

func TestMariaDBFailuresAreReadable(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 1001)
	server := &fakeDatabaseServer{fail: fmt.Errorf("ERROR 1007: %w", database.ErrServer)}

	_, err := callDatabase(t, system, server, "db.create_database", map[string]any{"username": "budi", "database": "budi_shop"})

	agentErr := assertCode(t, err, rpc.CodeActionFailed)
	if agentErr.Message != "MariaDB could not create database budi_shop; the agent log has the details" {
		t.Fatalf("message = %q", agentErr.Message)
	}
}

func TestDatabaseReport(t *testing.T) {
	server := &fakeDatabaseServer{sizes: []database.Size{{Name: "budi_shop", Bytes: 1048576}}}

	result, err := callDatabase(t, newFakeSystem(), server, "db.report", map[string]any{})
	if err != nil || string(result) != `{"databases":[{"name":"budi_shop","size_bytes":1048576}]}` {
		t.Fatalf("report: %s, %v", result, err)
	}
}
