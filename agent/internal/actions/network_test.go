package actions

import (
	"context"
	"encoding/json"
	"net/netip"
	"slices"
	"testing"

	"rayxo/agent/internal/network"
	"rayxo/agent/internal/rpc"
)

// fakeAddresses has the server's own 203.0.113.5 and 192.0.2.10, which
// rayxo added.
type fakeAddresses struct {
	calls []string
}

func (f *fakeAddresses) List(context.Context) ([]network.Address, error) {
	return []network.Address{
		{Address: netip.MustParseAddr("203.0.113.5"), Prefix: 24, Interface: "eth0", Primary: true},
		{Address: netip.MustParseAddr("192.0.2.10"), Prefix: 32, Interface: "eth0", Added: true},
	}, nil
}

func (f *fakeAddresses) Add(_ context.Context, ip netip.Addr, prefix int) (bool, error) {
	f.calls = append(f.calls, "add "+netip.PrefixFrom(ip, prefix).String())

	switch ip.String() {
	case "203.0.113.5":
		return false, network.ErrPrimary
	case "203.0.113.6":
		return false, nil
	}

	return true, nil
}

func (f *fakeAddresses) Remove(_ context.Context, ip netip.Addr) error {
	f.calls = append(f.calls, "remove "+ip.String())

	if ip.String() != "192.0.2.10" {
		return network.ErrNotAdded
	}

	return nil
}

func callNetwork(t *testing.T, addresses *fakeAddresses, method string, params any) (json.RawMessage, error) {
	t.Helper()

	raw, err := json.Marshal(params)
	if err != nil {
		t.Fatal(err)
	}

	registered := networkMethods(addresses)[method]

	if registered.LockKey != nil {
		if key, err := registered.LockKey(raw); err != nil || key != "network" {
			t.Fatalf("%s lock key = %q, %v", method, key, err)
		}
	}

	result, err := registered.Handler(context.Background(), raw)
	if err != nil {
		return nil, err
	}

	encoded, err := json.Marshal(result)
	if err != nil {
		t.Fatal(err)
	}

	return encoded, nil
}

func TestNetworkMethodsAreRegistered(t *testing.T) {
	registry := rpc.NewRegistry()
	Register(registry, Config{})

	for _, name := range []string{"network.addresses", "network.add_address", "network.remove_address"} {
		if !slices.Contains(registry.Names(), name) {
			t.Errorf("method %s is not registered", name)
		}
	}
}

func TestNetworkAddressesListsTheServersAddresses(t *testing.T) {
	result, err := callNetwork(t, &fakeAddresses{}, "network.addresses", map[string]any{})
	if err != nil {
		t.Fatal(err)
	}

	want := `{"addresses":[{"address":"203.0.113.5","prefix":24,"interface":"eth0","added":false,"primary":true},{"address":"192.0.2.10","prefix":32,"interface":"eth0","added":true,"primary":false}]}`
	if string(result) != want {
		t.Errorf("result = %s", result)
	}
}

func TestAddAddressReportsWhetherRayxoAddedIt(t *testing.T) {
	addresses := &fakeAddresses{}

	result, err := callNetwork(t, addresses, "network.add_address", map[string]any{"address": "192.0.2.11", "prefix": 32})
	if err != nil || string(result) != `{"added":true}` {
		t.Fatalf("result = %s, %v", result, err)
	}

	result, err = callNetwork(t, addresses, "network.add_address", map[string]any{"address": "203.0.113.6", "prefix": 32})
	if err != nil || string(result) != `{"added":false}` {
		t.Fatalf("result = %s, %v", result, err)
	}

	_, err = callNetwork(t, addresses, "network.add_address", map[string]any{"address": "203.0.113.5", "prefix": 32})
	if agentErr := assertCode(t, err, rpc.CodeActionFailed); agentErr.Message != "203.0.113.5 is the server's main address; websites without an address of their own use it" {
		t.Errorf("message = %q", agentErr.Message)
	}

	if !slices.Equal(addresses.calls, []string{"add 192.0.2.11/32", "add 203.0.113.6/32", "add 203.0.113.5/32"}) {
		t.Errorf("calls = %q", addresses.calls)
	}
}

func TestRemoveAddressOnlyRemovesWhatRayxoAdded(t *testing.T) {
	addresses := &fakeAddresses{}

	if _, err := callNetwork(t, addresses, "network.remove_address", map[string]any{"address": "192.0.2.10"}); err != nil {
		t.Fatal(err)
	}

	_, err := callNetwork(t, addresses, "network.remove_address", map[string]any{"address": "203.0.113.5"})

	agentErr := assertCode(t, err, rpc.CodeActionFailed)
	if agentErr.Message != "203.0.113.5 was not added by RAYXO, so it stays" {
		t.Errorf("message = %q", agentErr.Message)
	}
}

func TestNetworkMethodsRejectInvalidParams(t *testing.T) {
	invalid := map[string][]map[string]any{
		"network.add_address": {
			{"address": "192.0.2.10"},
			{"address": "192.0.2.10", "prefix": 33},
			{"address": "2001:db8::10", "prefix": 129},
			{"address": "127.0.0.1", "prefix": 32},
			{"address": "192.0.2.10/32", "prefix": 32},
			{"address": "192.0.2.10; reboot", "prefix": 32},
			{"address": "192.0.2.10", "prefix": 32, "interface": "eth1"},
			{"prefix": 32},
		},
		"network.remove_address": {
			{},
			{"address": "fe80::1"},
			{"address": "192.0.2.10", "prefix": 32},
		},
	}

	for method, cases := range invalid {
		for _, params := range cases {
			addresses := &fakeAddresses{}

			_, err := callNetwork(t, addresses, method, params)

			assertCode(t, err, rpc.CodeInvalidParams)

			if len(addresses.calls) != 0 {
				t.Errorf("%s(%v) reached the network: %q", method, params, addresses.calls)
			}
		}
	}
}
