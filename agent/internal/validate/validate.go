// Package validate checks untrusted input before any of it reaches the
// system. Every action validates its params with these helpers, even though
// the panel validated them first.
package validate

import (
	"errors"
	"fmt"
	"net/netip"
	"regexp"
	"strings"
)

var (
	usernamePattern    = regexp.MustCompile(`^[a-z][a-z0-9]{2,15}$`)
	domainLabelPattern = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$`)
	phpVersionPattern  = regexp.MustCompile(`^[0-9]\.[0-9]$`)
)

// reservedUsernames are system and service accounts. Keep in sync with
// App\Rules\ValidUsername::RESERVED in the panel.
var reservedUsernames = map[string]bool{
	"adm": true, "apache": true, "backup": true, "bin": true, "clamav": true, "daemon": true,
	"dnsmasq": true, "dovecot": true, "dovenull": true, "ftp": true, "games": true, "gnats": true,
	"irc": true, "landscape": true, "list": true, "lp": true, "lxd": true, "mail": true, "man": true,
	"messagebus": true, "mysql": true, "news": true, "nginx": true, "nobody": true, "rayxod": true,
	"rayxo": true, "operator": true, "pdns": true, "polkitd": true, "pollinate": true,
	"postfix": true, "proxy": true, "pureftpd": true, "redis": true, "root": true, "rspamd": true,
	"sshd": true, "staff": true, "sudo": true, "sync": true, "sys": true, "syslog": true,
	"systemd": true, "tcpdump": true, "tss": true, "ubuntu": true, "users": true, "uucp": true,
	"uuidd": true, "vmail": true,
	// An account's databases are named <username>_<name>; these would claim
	// MariaDB's own information_schema and performance_schema.
	"information": true, "performance": true,
	// Privileged groups: an account must never share a name with one, or a
	// group created later (e.g. by installing Docker) could be handed to it.
	"audio": true, "cdrom": true, "crontab": true, "dialout": true, "disk": true, "docker": true,
	"floppy": true, "input": true, "kmem": true, "kvm": true, "libvirt": true, "netdev": true,
	"plugdev": true, "render": true, "shadow": true, "systemd-journal": true, "tape": true,
	"tty": true, "utmp": true, "video": true, "wheel": true, "www": true,
}

// Username returns an error unless name is a valid account name: 3 to 16
// lowercase letters and digits, starting with a letter, and not reserved.
func Username(name string) error {
	if !usernamePattern.MatchString(name) {
		return fmt.Errorf("username %q must be 3 to 16 lowercase letters or digits and start with a letter", name)
	}

	if reservedUsernames[name] {
		return fmt.Errorf("username %q is reserved by the system", name)
	}

	return nil
}

// Domain returns an error unless name is a lowercase ASCII domain name
// (internationalized names must already be converted to punycode) with at
// least two labels and a top-level label that is not purely numeric.
func Domain(name string) error {
	if name == "" {
		return errors.New("domain must not be empty")
	}

	if len(name) > 253 {
		return fmt.Errorf("domain %q is longer than 253 characters", name)
	}

	labels := strings.Split(name, ".")
	if len(labels) < 2 {
		return fmt.Errorf("domain %q must have at least two labels, like example.com", name)
	}

	for _, label := range labels {
		if !domainLabelPattern.MatchString(label) {
			return fmt.Errorf("domain %q has an invalid label %q", name, label)
		}
	}

	if !strings.ContainsAny(labels[len(labels)-1], "abcdefghijklmnopqrstuvwxyz") {
		return fmt.Errorf("domain %q must end in a top-level domain, not a number", name)
	}

	return nil
}

// PHPVersion returns an error unless version is a PHP major.minor version
// such as "8.4". It only checks the form; whether that version is installed
// is up to the caller.
func PHPVersion(version string) error {
	if !phpVersionPattern.MatchString(version) {
		return fmt.Errorf("PHP version %q must look like 8.4", version)
	}

	return nil
}

// IPAddress parses address, an IPv4 or IPv6 address the server can serve
// on, in its canonical form: not loopback, link-local, multicast or
// unspecified, without a zone, and no IPv4 address written as IPv6.
func IPAddress(address string) (netip.Addr, error) {
	ip, err := netip.ParseAddr(address)
	if err != nil || ip.String() != address || ip.Zone() != "" || ip.Is4In6() {
		return netip.Addr{}, fmt.Errorf("%q is not an IP address in its usual form", address)
	}

	if ip.IsLoopback() || ip.IsLinkLocalUnicast() || ip.IsMulticast() || ip.IsUnspecified() || ip.IsLinkLocalMulticast() || ip.IsInterfaceLocalMulticast() {
		return netip.Addr{}, fmt.Errorf("%s is not an address a server can serve on", address)
	}

	return ip, nil
}
