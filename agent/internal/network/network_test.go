package network

import (
	"context"
	"encoding/json"
	"errors"
	"net/netip"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
)

// fakeIP is ip(8) on a server whose eth0 has 203.0.113.5/24 and
// 2001:db8:1::5/64, with default routes for both versions on eth0.
type fakeIP struct {
	// addresses are "address/prefix" by interface.
	addresses map[string][]string
	calls     []string
	noIPv6    bool
	fail      string
}

func newFakeIP() *fakeIP {
	return &fakeIP{addresses: map[string][]string{
		"lo":   {"127.0.0.1/8", "::1/128"},
		"eth0": {"203.0.113.5/24", "2001:db8:1::5/64", "fe80::1/64"},
	}}
}

func (f *fakeIP) run(_ context.Context, program string, args ...string) ([]byte, error) {
	if program != ipProgram {
		return nil, errors.New("unexpected program " + program)
	}

	call := strings.Join(args, " ")
	f.calls = append(f.calls, call)

	if f.fail != "" && strings.HasPrefix(call, f.fail) {
		return nil, errors.New("ip failed")
	}

	switch {
	case call == "-j address show":
		type info struct {
			Family string `json:"family"`
			Local  string `json:"local"`
			Prefix int    `json:"prefixlen"`
			Scope  string `json:"scope"`
		}

		type device struct {
			Name      string `json:"ifname"`
			Addresses []info `json:"addr_info"`
		}

		var devices []device
		for _, name := range []string{"lo", "eth0"} {
			d := device{Name: name}
			for _, address := range f.addresses[name] {
				prefix := netip.MustParsePrefix(address)
				family, scope := "inet", "global"
				if prefix.Addr().Is6() {
					family = "inet6"
				}
				if name == "lo" {
					scope = "host"
				} else if prefix.Addr().IsLinkLocalUnicast() {
					scope = "link"
				}
				d.Addresses = append(d.Addresses, info{family, prefix.Addr().String(), prefix.Bits(), scope})
			}
			devices = append(devices, d)
		}

		return json.Marshal(devices)
	case call == "-4 -j route show default":
		return []byte(`[{"dst":"default","gateway":"203.0.113.1","dev":"eth0","flags":[]}]`), nil
	case call == "-6 -j route show default":
		if f.noIPv6 {
			return []byte(`[]`), nil
		}

		return []byte(`[{"dst":"default","gateway":"2001:db8:1::1","dev":"eth0","flags":[]}]`), nil
	case strings.HasPrefix(call, "address add "):
		fields := strings.Fields(call)
		f.addresses[fields[4]] = append(f.addresses[fields[4]], fields[2])

		return nil, nil
	case strings.HasPrefix(call, "address del "):
		fields := strings.Fields(call)
		f.addresses[fields[4]] = slices.DeleteFunc(f.addresses[fields[4]], func(address string) bool { return address == fields[2] })

		return nil, nil
	}

	return nil, errors.New("unexpected call " + call)
}

func newManager(t *testing.T) (*Manager, *fakeIP, string) {
	t.Helper()

	state := filepath.Join(t.TempDir(), "rayxo", "ip-addresses")
	ip := newFakeIP()

	return New(Config{StateFile: state, Run: ip.run}), ip, state
}

func TestListReturnsTheGlobalAddresses(t *testing.T) {
	manager, _, _ := newManager(t)

	addresses, err := manager.List(context.Background())
	if err != nil {
		t.Fatal(err)
	}

	want := []Address{
		{Address: netip.MustParseAddr("203.0.113.5"), Prefix: 24, Interface: "eth0", Primary: true},
		{Address: netip.MustParseAddr("2001:db8:1::5"), Prefix: 64, Interface: "eth0", Primary: true},
	}

	if !slices.Equal(addresses, want) {
		t.Errorf("addresses = %+v", addresses)
	}
}

func TestAddPutsTheAddressOnTheDefaultInterfaceAndRecordsIt(t *testing.T) {
	manager, ip, state := newManager(t)

	for _, address := range []struct {
		ip     string
		prefix int
		call   string
	}{
		{"192.0.2.10", 32, "address add 192.0.2.10/32 dev eth0"},
		{"2001:db8:2::10", 128, "address add 2001:db8:2::10/128 dev eth0 nodad preferred_lft 0"},
	} {
		added, err := manager.Add(context.Background(), netip.MustParseAddr(address.ip), address.prefix)
		if err != nil || !added {
			t.Fatalf("Add(%s) = %v, %v", address.ip, added, err)
		}

		if !slices.Contains(ip.calls, address.call) {
			t.Errorf("calls = %q, want %q", ip.calls, address.call)
		}
	}

	content, _ := os.ReadFile(state)
	if !strings.HasSuffix(string(content), "192.0.2.10/32 eth0\n2001:db8:2::10/128 eth0\n") {
		t.Errorf("state = %q", content)
	}

	addresses, _ := manager.List(context.Background())
	if len(addresses) != 4 || !addresses[2].Added || addresses[2].Primary || addresses[0].Added {
		t.Errorf("addresses = %+v", addresses)
	}
}

func TestAddingAnAddressAgainChangesNothing(t *testing.T) {
	manager, ip, _ := newManager(t)

	if _, err := manager.Add(context.Background(), netip.MustParseAddr("192.0.2.10"), 32); err != nil {
		t.Fatal(err)
	}

	ip.calls = nil

	added, err := manager.Add(context.Background(), netip.MustParseAddr("192.0.2.10"), 32)
	if err != nil || !added {
		t.Fatalf("Add = %v, %v", added, err)
	}

	if slices.ContainsFunc(ip.calls, func(call string) bool { return strings.HasPrefix(call, "address add") }) {
		t.Errorf("added again: %q", ip.calls)
	}
}

func TestTheMainAddressesCannotBeAdded(t *testing.T) {
	manager, _, state := newManager(t)

	for _, address := range []string{"203.0.113.5", "2001:db8:1::5"} {
		if _, err := manager.Add(context.Background(), netip.MustParseAddr(address), 32); !errors.Is(err, ErrPrimary) {
			t.Errorf("Add(%s) = %v, want ErrPrimary", address, err)
		}
	}

	if _, err := os.Stat(state); !os.IsNotExist(err) {
		t.Error("a main address was recorded")
	}
}

func TestAnAddressTheServerHasStaysItsOwn(t *testing.T) {
	manager, ip, state := newManager(t)
	ip.addresses["eth0"] = append(ip.addresses["eth0"], "203.0.113.6/24")

	added, err := manager.Add(context.Background(), netip.MustParseAddr("203.0.113.6"), 32)
	if err != nil || added {
		t.Fatalf("Add = %v, %v", added, err)
	}

	if _, err := os.Stat(state); !os.IsNotExist(err) {
		t.Error("the server's own address was recorded")
	}

	if err := manager.Remove(context.Background(), netip.MustParseAddr("203.0.113.6")); !errors.Is(err, ErrNotAdded) {
		t.Fatalf("Remove = %v, want ErrNotAdded", err)
	}

	if !slices.Contains(ip.addresses["eth0"], "203.0.113.6/24") {
		t.Error("the server's own address was removed")
	}
}

func TestAnIPv6AddressGoesToTheIPv4InterfaceWithoutAnIPv6Route(t *testing.T) {
	manager, ip, _ := newManager(t)
	ip.noIPv6 = true

	if _, err := manager.Add(context.Background(), netip.MustParseAddr("2001:db8:2::10"), 128); err != nil {
		t.Fatal(err)
	}

	if !slices.Contains(ip.addresses["eth0"], "2001:db8:2::10/128") {
		t.Errorf("addresses = %q", ip.addresses)
	}
}

func TestAddRefusesBadAddresses(t *testing.T) {
	manager, ip, _ := newManager(t)

	for _, address := range []struct {
		ip     string
		prefix int
	}{
		{"127.0.0.2", 32},
		{"169.254.1.1", 32},
		{"192.0.2.10", 0},
		{"192.0.2.10", 33},
		{"2001:db8:2::10", 129},
		{"ff02::1", 128},
	} {
		if _, err := manager.Add(context.Background(), netip.MustParseAddr(address.ip), address.prefix); err == nil {
			t.Errorf("Add(%s/%d) = nil, want an error", address.ip, address.prefix)
		}
	}

	if len(ip.calls) != 0 {
		t.Errorf("ran ip: %q", ip.calls)
	}
}

func TestRemoveTakesTheAddressOffAndForgetsIt(t *testing.T) {
	manager, ip, state := newManager(t)

	for _, address := range []string{"192.0.2.10", "192.0.2.11"} {
		if _, err := manager.Add(context.Background(), netip.MustParseAddr(address), 32); err != nil {
			t.Fatal(err)
		}
	}

	if err := manager.Remove(context.Background(), netip.MustParseAddr("192.0.2.10")); err != nil {
		t.Fatal(err)
	}

	if slices.Contains(ip.addresses["eth0"], "192.0.2.10/32") || !slices.Contains(ip.addresses["eth0"], "192.0.2.11/32") {
		t.Errorf("addresses = %q", ip.addresses["eth0"])
	}

	content, _ := os.ReadFile(state)
	if strings.Contains(string(content), "192.0.2.10") || !strings.Contains(string(content), "192.0.2.11/32 eth0") {
		t.Errorf("state = %q", content)
	}
}

func TestRemovePutsBackTheAddressesTheKernelTookWithIt(t *testing.T) {
	manager, ip, _ := newManager(t)

	for _, address := range []string{"192.0.2.10", "192.0.2.11"} {
		if _, err := manager.Add(context.Background(), netip.MustParseAddr(address), 24); err != nil {
			t.Fatal(err)
		}
	}

	// The kernel drops the secondary address of a subnet with its first.
	ip.addresses["eth0"] = slices.DeleteFunc(ip.addresses["eth0"], func(address string) bool { return address == "192.0.2.11/24" })

	if err := manager.Remove(context.Background(), netip.MustParseAddr("192.0.2.10")); err != nil {
		t.Fatal(err)
	}

	if !slices.Contains(ip.addresses["eth0"], "192.0.2.11/24") {
		t.Errorf("192.0.2.11 was not put back: %q", ip.addresses["eth0"])
	}
}

func TestRemoveOfAnAddressAlreadyGoneForgetsIt(t *testing.T) {
	manager, ip, _ := newManager(t)

	if _, err := manager.Add(context.Background(), netip.MustParseAddr("192.0.2.10"), 32); err != nil {
		t.Fatal(err)
	}

	ip.addresses["eth0"] = slices.DeleteFunc(ip.addresses["eth0"], func(address string) bool { return address == "192.0.2.10/32" })
	ip.fail = "address del"

	if err := manager.Remove(context.Background(), netip.MustParseAddr("192.0.2.10")); err != nil {
		t.Fatal(err)
	}

	addresses, _ := manager.List(context.Background())
	if slices.ContainsFunc(addresses, func(address Address) bool { return address.Added }) {
		t.Errorf("addresses = %+v", addresses)
	}
}

func TestRestoreAddsTheLostAddressesAgain(t *testing.T) {
	manager, ip, state := newManager(t)

	if err := os.MkdirAll(filepath.Dir(state), 0o755); err != nil {
		t.Fatal(err)
	}

	content := "# comment\n192.0.2.10/32 eth0\n2001:db8:2::10/128 eth0\n203.0.113.5/24 eth0\nbroken line\n127.0.0.1/8 lo\n192.0.2.12/32 -bad\n"
	if err := os.WriteFile(state, []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}

	if err := manager.Restore(context.Background()); err != nil {
		t.Fatal(err)
	}

	adds := slices.DeleteFunc(slices.Clone(ip.calls), func(call string) bool { return !strings.HasPrefix(call, "address add") })
	want := []string{"address add 192.0.2.10/32 dev eth0", "address add 2001:db8:2::10/128 dev eth0 nodad preferred_lft 0"}

	if !slices.Equal(adds, want) {
		t.Errorf("adds = %q", adds)
	}
}

func TestRestoreWithoutAddressesRunsNothing(t *testing.T) {
	manager, ip, _ := newManager(t)

	if err := manager.Restore(context.Background()); err != nil {
		t.Fatal(err)
	}

	if len(ip.calls) != 0 {
		t.Errorf("calls = %q", ip.calls)
	}
}
