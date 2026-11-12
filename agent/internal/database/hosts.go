package database

import (
	"errors"
	"fmt"
	"net/netip"
	"regexp"
	"slices"
	"strings"
)

// A remote host is an IPv4 or IPv6 address, an IPv4 network as
// address/netmask (192.168.1.0/255.255.255.0), an IPv4 prefix ending in a
// wildcard (192.168.1.%, 10.%), or % for any host. Host names are not
// allowed: they would make every login wait on DNS.
var ipv4PrefixPattern = regexp.MustCompile(`^(?:(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])\.){1,3}%$`)

// ValidateHost returns an error unless host is a remote host an account may
// allow, in the form MariaDB keeps it.
func ValidateHost(host string) error {
	if host == "%" || ipv4PrefixPattern.MatchString(host) {
		return nil
	}

	if address, mask, ok := strings.Cut(host, "/"); ok {
		network, err := netip.ParseAddr(address)
		netmask, maskErr := netip.ParseAddr(mask)

		if err != nil || maskErr != nil || !network.Is4() || !netmask.Is4() || !contiguousMask(netmask) ||
			network.String() != address || netmask.String() != mask {
			return fmt.Errorf("remote host %q must be an IPv4 network as address/netmask, such as 192.168.1.0/255.255.255.0", host)
		}

		return nil
	}

	address, err := netip.ParseAddr(host)
	if err != nil || address.Zone() != "" || address.String() != host || address.Is4In6() {
		return fmt.Errorf("remote host %q must be an IP address, an IPv4 network, a prefix like 192.168.1.%% or %%", host)
	}

	if address.IsLoopback() || address.IsUnspecified() {
		return fmt.Errorf("remote host %q is this server, which already connects as localhost", host)
	}

	return nil
}

// ValidateHosts checks every host and that none is listed twice.
func ValidateHosts(hosts []string) error {
	if len(hosts) > MaxRemoteHosts {
		return fmt.Errorf("an account may allow at most %d remote hosts", MaxRemoteHosts)
	}

	for i, host := range hosts {
		if err := ValidateHost(host); err != nil {
			return err
		}

		if slices.Contains(hosts[:i], host) {
			return errors.New("remote host " + host + " is listed twice")
		}
	}

	return nil
}

// validHostColumn reports whether a Host of mysql.user or mysql.db is one
// an account user can have, so it can go into SQL as it is.
func validHostColumn(host string) bool {
	return host == "localhost" || ValidateHost(host) == nil
}

// contiguousMask reports whether mask is a netmask: ones, then zeros.
func contiguousMask(mask netip.Addr) bool {
	bytes := mask.As4()
	value := uint32(bytes[0])<<24 | uint32(bytes[1])<<16 | uint32(bytes[2])<<8 | uint32(bytes[3])
	inverted := ^value

	return inverted&(inverted+1) == 0
}
