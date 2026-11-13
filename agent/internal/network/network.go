// Package network adds the server's extra IP addresses to its network
// interface and takes them off again.
//
// Addresses are added at runtime with ip(8), not in netplan: applying
// netplan on a remote server can take its network down. The addresses
// rayxo added are kept in a state file and added again when the agent
// starts, after a reboot. Only those can be removed; the server's own
// addresses are never touched.
package network

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"net/netip"
	"os"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"sync"

	"rayxo/agent/internal/validate"
)

const ipProgram = "/usr/sbin/ip"

// ErrNotAdded is returned for an address rayxo did not add, which it may
// not take off.
var ErrNotAdded = errors.New("the address was not added by rayxo")

// ErrPrimary is returned for the main address of an interface: the
// address every website without one of its own shares, which is no
// account's to have.
var ErrPrimary = errors.New("the address is the main address of its interface")

// Address is an address of the server.
type Address struct {
	Address   netip.Addr
	Prefix    int
	Interface string
	// Added is whether rayxo added it.
	Added bool
	// Primary is whether it is the first global address of its version
	// on its interface: the one the server uses as its own.
	Primary bool
}

// Config holds where the Manager keeps its state and how it runs ip.
type Config struct {
	// StateFile lists the addresses rayxo added, one "address/prefix
	// interface" per line.
	StateFile string
	Run       func(ctx context.Context, program string, args ...string) ([]byte, error)
}

// Manager adds and removes addresses.
type Manager struct {
	cfg Config
	mu  sync.Mutex
}

// New returns a Manager for cfg.
func New(cfg Config) *Manager {
	return &Manager{cfg: cfg}
}

// CheckPrefix checks the prefix length of an address of the version of ip.
func CheckPrefix(ip netip.Addr, prefix int) error {
	if prefix < 1 || prefix > ip.BitLen() {
		return fmt.Errorf("the prefix length of %s must be 1 to %d", ip, ip.BitLen())
	}

	return nil
}

// List returns the global addresses of the server's interfaces, those
// rayxo added marked.
func (m *Manager) List(ctx context.Context) ([]Address, error) {
	m.mu.Lock()
	defer m.mu.Unlock()

	return m.list(ctx)
}

// Add adds ip with the prefix length to the interface of the default
// route and records it. An address the server already has is left as it
// is and reported as not added: it stays the server's own. A main address
// (Address.Primary) fails with ErrPrimary.
func (m *Manager) Add(ctx context.Context, ip netip.Addr, prefix int) (bool, error) {
	if _, err := validate.IPAddress(ip.String()); err != nil {
		return false, err
	}

	if err := CheckPrefix(ip, prefix); err != nil {
		return false, err
	}

	m.mu.Lock()
	defer m.mu.Unlock()

	addresses, err := m.list(ctx)
	if err != nil {
		return false, err
	}

	recorded, err := m.recorded()
	if err != nil {
		return false, err
	}

	if index := slices.IndexFunc(addresses, func(address Address) bool { return address.Address == ip }); index >= 0 {
		if addresses[index].Primary && !addresses[index].Added {
			return false, ErrPrimary
		}

		return addresses[index].Added, nil
	}

	device, err := m.defaultInterface(ctx, ip.Is6())
	if err != nil {
		return false, err
	}

	record := Address{Address: ip, Prefix: prefix, Interface: device, Added: true}
	if err := m.addToInterface(ctx, record); err != nil {
		return false, err
	}

	recorded = slices.DeleteFunc(recorded, func(address Address) bool { return address.Address == ip })
	if err := m.save(append(recorded, record)); err != nil {
		return false, errors.Join(err, m.deleteFromInterface(ctx, record))
	}

	return true, nil
}

// Remove takes an address rayxo added off its interface and forgets it.
// It fails with ErrNotAdded for any other address.
func (m *Manager) Remove(ctx context.Context, ip netip.Addr) error {
	m.mu.Lock()
	defer m.mu.Unlock()

	recorded, err := m.recorded()
	if err != nil {
		return err
	}

	index := slices.IndexFunc(recorded, func(address Address) bool { return address.Address == ip })
	if index < 0 {
		return ErrNotAdded
	}

	record := recorded[index]

	addresses, err := m.list(ctx)
	if err != nil {
		return err
	}

	if slices.ContainsFunc(addresses, func(address Address) bool { return address.Address == ip }) {
		if err := m.deleteFromInterface(ctx, record); err != nil {
			return err
		}
	}

	if err := m.save(slices.Delete(recorded, index, index+1)); err != nil {
		return err
	}

	// Taking off the first address of a subnet takes the others of it
	// with it; put back those rayxo added.
	return m.restore(ctx)
}

// Restore adds the recorded addresses the server lost again, as after a
// reboot.
func (m *Manager) Restore(ctx context.Context) error {
	m.mu.Lock()
	defer m.mu.Unlock()

	return m.restore(ctx)
}

func (m *Manager) restore(ctx context.Context) error {
	recorded, err := m.recorded()
	if err != nil {
		return err
	}

	if len(recorded) == 0 {
		return nil
	}

	addresses, err := m.list(ctx)
	if err != nil {
		return err
	}

	var failed []error

	for _, record := range recorded {
		if slices.ContainsFunc(addresses, func(address Address) bool { return address.Address == record.Address }) {
			continue
		}

		if err := m.addToInterface(ctx, record); err != nil {
			failed = append(failed, err)
		}
	}

	return errors.Join(failed...)
}

// list reads the global addresses of every interface.
func (m *Manager) list(ctx context.Context) ([]Address, error) {
	output, err := m.cfg.Run(ctx, ipProgram, "-j", "address", "show")
	if err != nil {
		return nil, fmt.Errorf("list the addresses: %w", err)
	}

	var interfaces []struct {
		Name      string `json:"ifname"`
		Addresses []struct {
			Family string `json:"family"`
			Local  string `json:"local"`
			Prefix int    `json:"prefixlen"`
			Scope  string `json:"scope"`
		} `json:"addr_info"`
	}

	if err := json.Unmarshal(output, &interfaces); err != nil {
		return nil, fmt.Errorf("read the addresses: %w", err)
	}

	recorded, err := m.recorded()
	if err != nil {
		return nil, err
	}

	addresses := []Address{}

	for _, device := range interfaces {
		seen := map[string]bool{}

		for _, info := range device.Addresses {
			ip, err := netip.ParseAddr(info.Local)
			if err != nil || info.Scope != "global" || (info.Family != "inet" && info.Family != "inet6") {
				continue
			}

			added := slices.ContainsFunc(recorded, func(address Address) bool { return address.Address == ip })
			addresses = append(addresses, Address{Address: ip, Prefix: info.Prefix, Interface: device.Name, Added: added, Primary: !seen[info.Family]})
			seen[info.Family] = true
		}
	}

	return addresses, nil
}

// defaultInterface is the interface of the default route of the version,
// or of IPv4's when IPv6 has none.
func (m *Manager) defaultInterface(ctx context.Context, ipv6 bool) (string, error) {
	families := [][]string{{"-4"}}
	if ipv6 {
		families = [][]string{{"-6"}, {"-4"}}
	}

	for _, family := range families {
		output, err := m.cfg.Run(ctx, ipProgram, append(family, "-j", "route", "show", "default")...)
		if err != nil {
			return "", fmt.Errorf("find the default route: %w", err)
		}

		var routes []struct {
			Device string `json:"dev"`
		}

		if err := json.Unmarshal(output, &routes); err != nil {
			return "", fmt.Errorf("read the default route: %w", err)
		}

		for _, route := range routes {
			if checkInterface(route.Device) == nil {
				return route.Device, nil
			}
		}
	}

	return "", errors.New("the server has no default route to add the address to")
}

func (m *Manager) addToInterface(ctx context.Context, record Address) error {
	args := []string{"address", "add", record.Address.String() + "/" + strconv.Itoa(record.Prefix), "dev", record.Interface}
	// An IPv6 address works at once, without duplicate address detection,
	// and is never picked as the source of the server's own connections:
	// source selection prefers the address sharing the longest prefix with
	// the destination, which could be an extra address the provider does
	// not route back. It still takes connections to it.
	if record.Address.Is6() {
		args = append(args, "nodad", "preferred_lft", "0")
	}

	if _, err := m.cfg.Run(ctx, ipProgram, args...); err != nil {
		return fmt.Errorf("add %s to %s: %w", record.Address, record.Interface, err)
	}

	return nil
}

func (m *Manager) deleteFromInterface(ctx context.Context, record Address) error {
	if _, err := m.cfg.Run(ctx, ipProgram, "address", "del", record.Address.String()+"/"+strconv.Itoa(record.Prefix), "dev", record.Interface); err != nil {
		return fmt.Errorf("remove %s from %s: %w", record.Address, record.Interface, err)
	}

	return nil
}

// recorded reads the state file; lines it cannot read are skipped.
func (m *Manager) recorded() ([]Address, error) {
	content, err := os.ReadFile(m.cfg.StateFile)
	if errors.Is(err, fs.ErrNotExist) {
		return nil, nil
	}

	if err != nil {
		return nil, err
	}

	var addresses []Address

	scanner := bufio.NewScanner(bytes.NewReader(content))
	for scanner.Scan() {
		fields := strings.Fields(scanner.Text())
		if len(fields) != 2 || checkInterface(fields[1]) != nil {
			continue
		}

		prefix, err := netip.ParsePrefix(fields[0])
		if err != nil || CheckPrefix(prefix.Addr(), prefix.Bits()) != nil {
			continue
		}

		if _, err := validate.IPAddress(prefix.Addr().String()); err != nil {
			continue
		}

		addresses = append(addresses, Address{Address: prefix.Addr(), Prefix: prefix.Bits(), Interface: fields[1], Added: true})
	}

	return addresses, scanner.Err()
}

func (m *Manager) save(addresses []Address) error {
	var content strings.Builder

	content.WriteString("# The IP addresses rayxod added; it adds them again when it starts.\n")

	for _, address := range addresses {
		content.WriteString(address.Address.String() + "/" + strconv.Itoa(address.Prefix) + " " + address.Interface + "\n")
	}

	if err := os.MkdirAll(filepath.Dir(m.cfg.StateFile), 0o755); err != nil {
		return err
	}

	temp := m.cfg.StateFile + ".tmp"
	if err := os.WriteFile(temp, []byte(content.String()), 0o644); err != nil {
		return err
	}

	return os.Rename(temp, m.cfg.StateFile)
}

// checkInterface accepts interface names ip(8) would, without anything a
// command line could misread.
func checkInterface(name string) error {
	if name == "" || len(name) > 15 || strings.HasPrefix(name, "-") || strings.ContainsAny(name, "/ \t\n:") {
		return fmt.Errorf("%q is not an interface name", name)
	}

	return nil
}
