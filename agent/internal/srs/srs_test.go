package srs

import (
	"bufio"
	"net"
	"strings"
	"testing"
	"time"
)

func newRewriter(t *testing.T, now time.Time) *Rewriter {
	t.Helper()

	r, err := New([]byte("0123456789abcdef0123456789abcdef"), "Mail.Example.NET.")
	if err != nil {
		t.Fatal(err)
	}

	r.now = func() time.Time { return now }

	return r
}

var day = time.Date(2026, 11, 12, 10, 0, 0, 0, time.UTC)

func TestForwardAndReverse(t *testing.T) {
	r := newRewriter(t, day)

	address, ok := r.Forward("Someone@Gmail.com")
	if !ok {
		t.Fatal("an outside address was not rewritten")
	}

	if !strings.HasPrefix(address, "SRS0=") || !strings.HasSuffix(address, "=Gmail.com=Someone@mail.example.net") {
		t.Fatalf("rewritten as %q", address)
	}

	for _, back := range []string{address, strings.ToLower(address)} {
		original, ok := r.Reverse(back)
		if !ok || !strings.EqualFold(original, "someone@gmail.com") {
			t.Fatalf("Reverse(%q) = %q, %v", back, original, ok)
		}
	}
}

func TestAddressesThatStay(t *testing.T) {
	r := newRewriter(t, day)

	for _, address := range []string{"root@mail.example.net", "ROOT@Mail.Example.Net", "root", "@gmail.com", "someone@", ""} {
		if rewritten, ok := r.Forward(address); ok {
			t.Errorf("Forward(%q) = %q", address, rewritten)
		}
	}

	for _, address := range []string{"someone@gmail.com", "info@mail.example.net", "SRS0=abcd=AB=gmail.com=x@other.example", "SRS0=xx@mail.example.net"} {
		if original, ok := r.Reverse(address); ok {
			t.Errorf("Reverse(%q) = %q", address, original)
		}
	}
}

func TestReverseRefusesForgedAndOldAddresses(t *testing.T) {
	r := newRewriter(t, day)
	address, _ := r.Forward("someone@gmail.com")

	// Another target with the same hash, as someone relaying would try.
	forged := strings.Replace(address, "=gmail.com=someone@", "=victim.example=someone@", 1)
	if _, ok := r.Reverse(forged); ok {
		t.Error("an address with a hash for another target was turned back")
	}

	other, _ := New([]byte("another secret key, long enough"), "mail.example.net")
	other.now = r.now
	if _, ok := other.Reverse(address); ok {
		t.Error("an address signed with another key was turned back")
	}

	r.now = func() time.Time { return day.AddDate(0, 0, MaxAgeDays) }
	if _, ok := r.Reverse(address); !ok {
		t.Error("an address of 21 days ago was refused")
	}

	r.now = func() time.Time { return day.AddDate(0, 0, MaxAgeDays+1) }
	if _, ok := r.Reverse(address); ok {
		t.Error("an address of 22 days ago was turned back")
	}
}

func TestAnotherForwardersAddressBecomesSRS1(t *testing.T) {
	first := newRewriter(t, day)
	first.domain = "fwd1.example"
	srs0, _ := first.Forward("someone@gmail.com")

	r := newRewriter(t, day)
	srs1, ok := r.Forward(srs0)
	if !ok || !strings.HasPrefix(srs1, "SRS1=") || !strings.Contains(srs1, "=fwd1.example==") {
		t.Fatalf("Forward(%q) = %q, %v", srs0, srs1, ok)
	}

	// A bounce goes back through the first forwarder.
	back, ok := r.Reverse(srs1)
	if !ok || back != srs0 {
		t.Fatalf("Reverse(%q) = %q, want %q", srs1, back, srs0)
	}

	// Forwarded once more, it keeps pointing at the first forwarder.
	third := newRewriter(t, day)
	third.domain = "fwd3.example"
	again, ok := third.Forward(srs1)
	if !ok || !strings.HasPrefix(again, "SRS1=") || !strings.Contains(again, "=fwd1.example==") || !strings.HasSuffix(again, "@fwd3.example") {
		t.Fatalf("Forward(%q) = %q, %v", srs1, again, ok)
	}

	if back, ok := third.Reverse(again); !ok || back != srs0 {
		t.Fatalf("Reverse(%q) = %q, want %q", again, back, srs0)
	}
}

func TestNewRefusesAShortSecretAndBadDomain(t *testing.T) {
	if _, err := New([]byte("short"), "mail.example.net"); err == nil {
		t.Error("a short secret was accepted")
	}

	if _, err := New([]byte("0123456789abcdef"), "not a domain"); err == nil {
		t.Error("a domain with spaces was accepted")
	}
}

func TestSocketmap(t *testing.T) {
	r := newRewriter(t, day)
	server, client := net.Pipe()
	go serveConn(server, r)
	defer client.Close()

	reader := bufio.NewReader(client)
	ask := func(request string) string {
		t.Helper()

		if _, err := client.Write(netstring(request)); err != nil {
			t.Fatal(err)
		}

		reply, err := readNetstring(reader)
		if err != nil {
			t.Fatal(err)
		}

		return reply
	}

	forward := ask("forward someone@gmail.com")
	if !strings.HasPrefix(forward, "OK SRS0=") {
		t.Fatalf("forward: %q", forward)
	}

	// Several requests on one connection, as Postfix sends them.
	if reply := ask("reverse " + strings.TrimPrefix(forward, "OK ")); reply != "OK someone@gmail.com" {
		t.Fatalf("reverse: %q", reply)
	}

	for request, want := range map[string]string{
		"forward root@mail.example.net": "NOTFOUND ",
		"reverse someone@gmail.com":     "NOTFOUND ",
		"lookup someone@gmail.com":      "PERM unknown map",
		"forward":                       "PERM malformed request",
	} {
		if reply := ask(request); reply != want {
			t.Errorf("%q: %q, want %q", request, reply, want)
		}
	}
}

func TestMalformedNetstrings(t *testing.T) {
	for _, input := range []string{"x:abc,", "3:abc;", "999999999:a,", "5:abc"} {
		if _, err := readNetstring(bufio.NewReader(strings.NewReader(input))); err == nil {
			t.Errorf("%q was read", input)
		}
	}
}
