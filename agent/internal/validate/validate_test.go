package validate

import (
	"strings"
	"testing"
)

func TestUsername(t *testing.T) {
	valid := []string{"budi", "abc", "user2026", "a23456789012345b"}
	for _, name := range valid {
		if err := Username(name); err != nil {
			t.Errorf("Username(%q) = %v, want nil", name, err)
		}
	}

	invalid := []string{"", "ab", "Budi", "1budi", "budi-santoso", "budi_s", "budi.s", "a234567890123456b", "root", "mysql", "rayxo", "budi\n"}
	for _, name := range invalid {
		if err := Username(name); err == nil {
			t.Errorf("Username(%q) = nil, want an error", name)
		}
	}
}

func TestDomain(t *testing.T) {
	valid := []string{"example.com", "sub.example.co.id", "xn--bcher-kva.example", "a-b.c-d.id", "123.example.com"}
	for _, name := range valid {
		if err := Domain(name); err != nil {
			t.Errorf("Domain(%q) = %v, want nil", name, err)
		}
	}

	invalid := []string{
		"",
		"localhost",
		"Example.com",
		"example.com.",
		".example.com",
		"exa mple.com",
		"-example.com",
		"example-.com",
		"example..com",
		"bücher.example",
		"example.com/../../etc",
		"example.com\nlisten 80",
		"192.168.1.1",
		strings.Repeat("a", 64) + ".com",
		strings.Repeat("abcdefghi.", 26) + "com",
	}
	for _, name := range invalid {
		if err := Domain(name); err == nil {
			t.Errorf("Domain(%q) = nil, want an error", name)
		}
	}
}

func TestPHPVersion(t *testing.T) {
	for _, version := range []string{"8.4", "7.4", "5.6", "0.0"} {
		if err := PHPVersion(version); err != nil {
			t.Errorf("PHPVersion(%q) = %v, want nil", version, err)
		}
	}

	invalid := []string{"", "8", "84", "8.", ".4", "8.4.1", "10.0", "8.10", "8,4", "v8.4", "8.4\n", "8.x", "../8.4", "8.4 "}
	for _, version := range invalid {
		if err := PHPVersion(version); err == nil {
			t.Errorf("PHPVersion(%q) = nil, want an error", version)
		}
	}
}

func TestIPAddress(t *testing.T) {
	for _, address := range []string{"203.0.113.10", "10.0.0.5", "2001:db8::10", "2a06:9f44:e000::36d7:b4d4"} {
		if ip, err := IPAddress(address); err != nil || ip.String() != address {
			t.Errorf("IPAddress(%q) = %v, %v, want the address", address, ip, err)
		}
	}

	invalid := []string{
		"", "203.0.113", "203.0.113.010", "203.0.113.10/32", " 203.0.113.10", "2001:DB8::10", "2001:db8:0::10",
		"::ffff:203.0.113.10", "fe80::1%eth0", "127.0.0.1", "::1", "0.0.0.0", "::", "169.254.1.1", "fe80::1",
		"224.0.0.1", "ff02::1", "example.com", "203.0.113.10;",
	}
	for _, address := range invalid {
		if _, err := IPAddress(address); err == nil {
			t.Errorf("IPAddress(%q) = nil, want an error", address)
		}
	}
}
