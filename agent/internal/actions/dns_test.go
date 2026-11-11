package actions

import (
	"context"
	"encoding/json"
	"errors"
	"slices"
	"testing"

	"rayxo/agent/internal/dns"
	"rayxo/agent/internal/rpc"
)

// fakeDNSServer records the zones synced and deleted.
type fakeDNSServer struct {
	synced  []dns.Zone
	deleted []string
	fail    error
}

func (f *fakeDNSServer) Sync(_ context.Context, zone dns.Zone) error {
	f.synced = append(f.synced, zone)

	return f.fail
}

func (f *fakeDNSServer) Delete(_ context.Context, name string) error {
	f.deleted = append(f.deleted, name)

	return f.fail
}

func callDNS(t *testing.T, server *fakeDNSServer, method string, params any) error {
	t.Helper()

	raw, err := json.Marshal(params)
	if err != nil {
		t.Fatal(err)
	}

	handler := dnsMethods(server)[method]

	key, err := handler.LockKey(raw)
	if err != nil {
		return err
	}

	if key != "account:budi" {
		t.Fatalf("%s locks %q", method, key)
	}

	_, err = handler.Handler(context.Background(), raw)

	return err
}

func TestDNSMethodsAreRegistered(t *testing.T) {
	registry := rpc.NewRegistry()
	Register(registry, Config{})

	for _, name := range []string{"dns.sync_zone", "dns.delete_zone"} {
		if !slices.Contains(registry.Names(), name) {
			t.Fatalf("%s is not registered", name)
		}
	}
}

func TestSyncZoneSendsTheWholeZone(t *testing.T) {
	server := &fakeDNSServer{}

	err := callDNS(t, server, "dns.sync_zone", map[string]any{
		"username":    "budi",
		"zone":        "example.com",
		"nameservers": []string{"ns1.example.net", "ns2.example.net"},
		"records": []map[string]any{
			{"name": "example.com", "type": "A", "content": "203.0.113.10", "ttl": 3600},
			{"name": "example.com", "type": "MX", "content": "mail.example.com", "ttl": 3600, "priority": 10},
		},
	})
	if err != nil {
		t.Fatal(err)
	}

	if len(server.synced) != 1 {
		t.Fatalf("synced = %+v", server.synced)
	}

	zone := server.synced[0]
	if zone.Name != "example.com" || len(zone.Nameservers) != 2 || len(zone.Records) != 2 || zone.Records[1].Priority != 10 {
		t.Fatalf("zone = %+v", zone)
	}
}

func TestDeleteZone(t *testing.T) {
	server := &fakeDNSServer{}

	if err := callDNS(t, server, "dns.delete_zone", map[string]any{"username": "budi", "zone": "example.com"}); err != nil {
		t.Fatal(err)
	}

	if !slices.Equal(server.deleted, []string{"example.com"}) {
		t.Fatalf("deleted = %v", server.deleted)
	}
}

func TestInvalidZonesAreRefusedBeforeTheDNSServer(t *testing.T) {
	invalid := []map[string]any{
		{"username": "budi", "zone": "example.com", "nameservers": []string{}},
		{"username": "budi", "zone": "../etc", "nameservers": []string{"ns1.example.net"}},
		{"username": "budi", "zone": "example.com", "nameservers": []string{"ns1.example.net"}, "records": []map[string]any{
			{"name": "other.org", "type": "A", "content": "203.0.113.10", "ttl": 3600},
		}},
		{"username": "budi", "zone": "example.com", "nameservers": []string{"ns1.example.net"}, "extra": true},
	}

	for _, params := range invalid {
		server := &fakeDNSServer{}

		err := callDNS(t, server, "dns.sync_zone", params)
		assertCode(t, err, rpc.CodeInvalidParams)

		if len(server.synced) != 0 {
			t.Fatalf("%v reached the DNS server", params)
		}
	}
}

func TestDNSServerErrorsFailTheAction(t *testing.T) {
	server := &fakeDNSServer{fail: errors.New("the DNS server could not do it: Backend error")}

	err := callDNS(t, server, "dns.delete_zone", map[string]any{"username": "budi", "zone": "example.com"})
	if agentErr := assertCode(t, err, rpc.CodeActionFailed); agentErr.Message != "the DNS server could not do it: Backend error" {
		t.Fatalf("message = %q", agentErr.Message)
	}
}
