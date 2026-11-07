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

	invalid := []string{"", "ab", "Budi", "1budi", "budi-santoso", "budi_s", "budi.s", "a234567890123456b", "root", "mysql", "nusapanel", "budi\n"}
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
