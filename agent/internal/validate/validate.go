// Package validate checks untrusted input before any of it reaches the
// system. Every action validates its params with these helpers, even though
// the panel validated them first.
package validate

import (
	"errors"
	"fmt"
	"regexp"
	"strings"
)

var (
	usernamePattern    = regexp.MustCompile(`^[a-z][a-z0-9]{2,15}$`)
	domainLabelPattern = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$`)
)

// reservedUsernames are system and service accounts. Keep in sync with
// App\Rules\ValidUsername::RESERVED in the panel.
var reservedUsernames = map[string]bool{
	"adm": true, "apache": true, "backup": true, "bin": true, "clamav": true, "daemon": true,
	"dnsmasq": true, "dovecot": true, "dovenull": true, "ftp": true, "games": true, "gnats": true,
	"irc": true, "landscape": true, "list": true, "lp": true, "lxd": true, "mail": true, "man": true,
	"messagebus": true, "mysql": true, "news": true, "nginx": true, "nobody": true, "nusad": true,
	"nusapanel": true, "operator": true, "pdns": true, "polkitd": true, "pollinate": true,
	"postfix": true, "proxy": true, "pureftpd": true, "redis": true, "root": true, "rspamd": true,
	"sshd": true, "staff": true, "sudo": true, "sync": true, "sys": true, "syslog": true,
	"systemd": true, "tcpdump": true, "tss": true, "ubuntu": true, "users": true, "uucp": true,
	"uuidd": true, "vmail": true,
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
