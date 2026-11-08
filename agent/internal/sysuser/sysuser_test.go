package sysuser

import (
	"reflect"
	"testing"
)

func TestParsePasswd(t *testing.T) {
	user, err := parsePasswd("budi:x:1001:1002:nusapanel,,,:/home/budi:/usr/sbin/nologin\n")
	if err != nil {
		t.Fatalf("parse failed: %v", err)
	}

	want := User{
		Name:    "budi",
		UID:     1001,
		GID:     1002,
		Comment: "nusapanel,,,",
		Home:    "/home/budi",
		Shell:   "/usr/sbin/nologin",
	}
	if user != want {
		t.Fatalf("got %+v, want %+v", user, want)
	}
}

func TestParsePasswdList(t *testing.T) {
	users, err := parsePasswdList([]byte("root:x:0:0:root:/root:/bin/bash\n\nbudi:x:1001:1001:nusapanel:/home/budi:/usr/sbin/nologin\n"))
	if err != nil {
		t.Fatalf("parse failed: %v", err)
	}

	want := []User{
		{Name: "root", UID: 0, GID: 0, Comment: "root", Home: "/root", Shell: "/bin/bash"},
		{Name: "budi", UID: 1001, GID: 1001, Comment: "nusapanel", Home: "/home/budi", Shell: "/usr/sbin/nologin"},
	}
	if !reflect.DeepEqual(users, want) {
		t.Fatalf("got %+v, want %+v", users, want)
	}

	if _, err := parsePasswdList([]byte("root:x:0:0:root:/root:/bin/bash\nbroken\n")); err == nil {
		t.Fatal("a malformed entry was accepted")
	}
}

func TestParsePasswdRejectsMalformedEntries(t *testing.T) {
	invalid := []string{
		"",
		"budi:x:1001:1001:nusapanel:/home/budi",
		"budi:x:1001:1001:nusapanel:/home/budi:/bin/sh:extra",
		"budi:x:-1:1001:nusapanel:/home/budi:/bin/sh",
		"budi:x:1001:abc:nusapanel:/home/budi:/bin/sh",
		"budi:x:4294967296:1001:nusapanel:/home/budi:/bin/sh",
	}
	for _, line := range invalid {
		if _, err := parsePasswd(line); err == nil {
			t.Errorf("parsePasswd(%q) = nil error, want an error", line)
		}
	}
}

func TestParseGroup(t *testing.T) {
	tests := map[string]Group{
		"nusa-access:x:998:www-data,budi\n": {Name: "nusa-access", GID: 998, Members: []string{"www-data", "budi"}},
		"budi:x:1001:":                      {Name: "budi", GID: 1001},
	}
	for line, want := range tests {
		group, err := parseGroup(line)
		if err != nil {
			t.Fatalf("parseGroup(%q) failed: %v", line, err)
		}

		if !reflect.DeepEqual(group, want) {
			t.Errorf("parseGroup(%q) = %+v, want %+v", line, group, want)
		}
	}

	if _, err := parseGroup("budi:x:1001"); err == nil {
		t.Error("expected an error for a group entry with 3 fields")
	}
}

func TestFirstLine(t *testing.T) {
	if line := firstLine([]byte("budi:x:1001:1001::/home/budi:/bin/sh\nother\n")); line != "budi:x:1001:1001::/home/budi:/bin/sh" {
		t.Fatalf("unexpected first line %q", line)
	}
}
