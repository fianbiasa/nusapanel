package dns

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"sync"
	"testing"
)

const testKey = "secret-api-key"

// fakePowerDNS answers like PowerDNS's API for the zones it holds. Like
// PowerDNS with SOA-EDIT-API, it moves the serial on every PATCH that does
// not set the SOA itself.
type fakePowerDNS struct {
	mu       sync.Mutex
	zones    map[string]map[string]apiRRset
	requests []string
	failWith string
}

func newFakePowerDNS(t *testing.T) (*fakePowerDNS, *Manager) {
	t.Helper()

	fake := &fakePowerDNS{zones: map[string]map[string]apiRRset{}}
	server := httptest.NewServer(http.HandlerFunc(fake.serve))
	t.Cleanup(server.Close)

	keyFile := filepath.Join(t.TempDir(), "pdns-api-key")
	if err := os.WriteFile(keyFile, []byte(testKey+"\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	return fake, New(Config{ServerURL: server.URL + "/api/v1/servers/localhost", APIKeyFile: keyFile})
}

func (f *fakePowerDNS) serve(w http.ResponseWriter, r *http.Request) {
	f.mu.Lock()
	defer f.mu.Unlock()

	path := strings.TrimPrefix(r.URL.Path, "/api/v1/servers/localhost")
	f.requests = append(f.requests, r.Method+" "+path)

	if r.Header.Get("X-API-Key") != testKey {
		http.Error(w, `{"error": "Unauthorized"}`, http.StatusUnauthorized)

		return
	}

	if f.failWith != "" {
		w.WriteHeader(http.StatusUnprocessableEntity)
		_ = json.NewEncoder(w).Encode(map[string]string{"error": f.failWith})

		return
	}

	name := strings.TrimPrefix(path, "/zones/")
	rrsets, exists := f.zones[name]

	switch {
	case r.Method == http.MethodPost && path == "/zones":
		var zone apiZone
		_ = json.NewDecoder(r.Body).Decode(&zone)

		if len(zone.Nameservers) != 0 {
			http.Error(w, `{"error": "nameservers must be empty when NS rrsets are given"}`, http.StatusUnprocessableEntity)

			return
		}

		f.zones[zone.Name] = map[string]apiRRset{}
		for _, rrset := range zone.RRsets {
			f.zones[zone.Name][rrset.Name+" "+rrset.Type] = rrset
		}

		w.WriteHeader(http.StatusCreated)
	case r.Method == http.MethodPut && path == "/cache/flush":
		_ = json.NewEncoder(w).Encode(map[string]any{"count": 1, "result": "Flushed cache."})
	case !exists:
		http.Error(w, `{"error": "Not Found"}`, http.StatusNotFound)
	case r.Method == http.MethodGet:
		zone := apiZone{Name: name}
		for _, rrset := range rrsets {
			zone.RRsets = append(zone.RRsets, rrset)
		}

		_ = json.NewEncoder(w).Encode(zone)
	case r.Method == http.MethodPatch:
		var zone apiZone
		_ = json.NewDecoder(r.Body).Decode(&zone)

		setsSOA := false

		for _, change := range zone.RRsets {
			key := change.Name + " " + change.Type
			setsSOA = setsSOA || change.Type == "SOA"

			if change.ChangeType == "DELETE" {
				delete(rrsets, key)
			} else {
				change.ChangeType = ""
				rrsets[key] = change
			}
		}

		if !setsSOA {
			for key, rrset := range rrsets {
				if rrset.Type == "SOA" {
					fields := strings.Fields(rrset.Records[0].Content)
					serial, _ := strconv.Atoi(fields[2])
					fields[2] = strconv.Itoa(serial + 1)
					rrset.Records[0].Content = strings.Join(fields, " ")
					rrsets[key] = rrset
				}
			}
		}

		w.WriteHeader(http.StatusNoContent)
	case r.Method == http.MethodDelete:
		delete(f.zones, name)
		w.WriteHeader(http.StatusNoContent)
	}
}

// records returns the zone's records as "name type ttl content" lines.
func (f *fakePowerDNS) records(zone string) []string {
	f.mu.Lock()
	defer f.mu.Unlock()

	var lines []string

	for _, rrset := range f.zones[zone] {
		for _, record := range rrset.Records {
			lines = append(lines, rrset.Name+" "+rrset.Type+" "+strconv.Itoa(rrset.TTL)+" "+record.Content)
		}
	}

	slices.Sort(lines)

	return lines
}

func (f *fakePowerDNS) count(method string) int {
	f.mu.Lock()
	defer f.mu.Unlock()

	n := 0

	for _, request := range f.requests {
		if strings.HasPrefix(request, method+" ") {
			n++
		}
	}

	return n
}

func exampleZone() Zone {
	return Zone{
		Name:        "example.com",
		Nameservers: []string{"ns1.example.net", "ns2.example.net"},
		Records: []Record{
			{Name: "example.com", Type: "A", Content: "203.0.113.10", TTL: 3600},
			{Name: "www.example.com", Type: "A", Content: "203.0.113.10", TTL: 3600},
			{Name: "example.com", Type: "MX", Content: "mail.example.com", TTL: 3600, Priority: 10},
			{Name: "example.com", Type: "TXT", Content: "v=spf1 a mx ~all", TTL: 3600},
			{Name: "ftp.example.com", Type: "CNAME", Content: "example.com.", TTL: 600},
			{Name: "_sip._tcp.example.com", Type: "SRV", Content: "5 5060 sip.example.com", TTL: 3600, Priority: 10},
			{Name: "example.com", Type: "CAA", Content: "0 issue letsencrypt.org", TTL: 3600},
			{Name: "example.com", Type: "AAAA", Content: "2001:DB8::10", TTL: 3600},
		},
	}
}

func TestSyncCreatesTheZoneInPowerDNSForm(t *testing.T) {
	fake, manager := newFakePowerDNS(t)

	if err := manager.Sync(context.Background(), exampleZone()); err != nil {
		t.Fatal(err)
	}

	want := []string{
		"_sip._tcp.example.com. SRV 3600 10 5 5060 sip.example.com.",
		"example.com. A 3600 203.0.113.10",
		"example.com. AAAA 3600 2001:db8::10",
		`example.com. CAA 3600 0 issue "letsencrypt.org"`,
		"example.com. MX 3600 10 mail.example.com.",
		"example.com. NS 3600 ns1.example.net.",
		"example.com. NS 3600 ns2.example.net.",
		"example.com. SOA 3600 ns1.example.net. hostmaster.example.com. 1 10800 3600 604800 3600",
		`example.com. TXT 3600 "v=spf1 a mx ~all"`,
		"ftp.example.com. CNAME 600 example.com.",
		"www.example.com. A 3600 203.0.113.10",
	}

	if got := fake.records("example.com."); !slices.Equal(got, want) {
		t.Fatalf("records =\n%s\nwant\n%s", strings.Join(got, "\n"), strings.Join(want, "\n"))
	}
}

func TestSyncingTheSameZoneAgainSendsNothing(t *testing.T) {
	fake, manager := newFakePowerDNS(t)

	for range 2 {
		if err := manager.Sync(context.Background(), exampleZone()); err != nil {
			t.Fatal(err)
		}
	}

	if fake.count(http.MethodPatch) != 0 || fake.count(http.MethodPost) != 1 {
		t.Fatalf("requests = %v", fake.requests)
	}

	if got := strings.Join(fake.records("example.com."), "\n"); !strings.Contains(got, "hostmaster.example.com. 1 10800") {
		t.Fatalf("the serial moved:\n%s", got)
	}
}

func TestSyncReplacesChangedRecordsAndRemovesOldOnes(t *testing.T) {
	fake, manager := newFakePowerDNS(t)
	zone := exampleZone()

	if err := manager.Sync(context.Background(), zone); err != nil {
		t.Fatal(err)
	}

	zone.Records = []Record{
		{Name: "example.com", Type: "A", Content: "203.0.113.10", TTL: 3600},
		{Name: "www.example.com", Type: "A", Content: "203.0.113.20", TTL: 3600},
		{Name: "www.example.com", Type: "A", Content: "203.0.113.21", TTL: 300},
	}

	if err := manager.Sync(context.Background(), zone); err != nil {
		t.Fatal(err)
	}

	want := []string{
		"example.com. A 3600 203.0.113.10",
		"example.com. NS 3600 ns1.example.net.",
		"example.com. NS 3600 ns2.example.net.",
		"example.com. SOA 3600 ns1.example.net. hostmaster.example.com. 2 10800 3600 604800 3600",
		"www.example.com. A 300 203.0.113.20",
		"www.example.com. A 300 203.0.113.21",
	}

	if got := fake.records("example.com."); !slices.Equal(got, want) {
		t.Fatalf("records =\n%s\nwant\n%s", strings.Join(got, "\n"), strings.Join(want, "\n"))
	}

	if fake.count(http.MethodPatch) != 1 {
		t.Fatalf("requests = %v", fake.requests)
	}
}

func TestNewNameserversMoveTheSOA(t *testing.T) {
	fake, manager := newFakePowerDNS(t)
	zone := exampleZone()

	if err := manager.Sync(context.Background(), zone); err != nil {
		t.Fatal(err)
	}

	zone.Nameservers = []string{"ns1.example.org"}

	if err := manager.Sync(context.Background(), zone); err != nil {
		t.Fatal(err)
	}

	got := strings.Join(fake.records("example.com."), "\n")
	for _, line := range []string{
		"example.com. NS 3600 ns1.example.org.",
		"example.com. SOA 3600 ns1.example.org. hostmaster.example.com. 2 10800 3600 604800 3600",
	} {
		if !strings.Contains(got, line) {
			t.Fatalf("records lack %q:\n%s", line, got)
		}
	}

	if strings.Contains(got, "ns2.example.net") {
		t.Fatalf("the old nameserver stayed:\n%s", got)
	}
}

func TestDeleteRemovesTheZoneAndIgnoresMissingOnes(t *testing.T) {
	fake, manager := newFakePowerDNS(t)

	if err := manager.Sync(context.Background(), exampleZone()); err != nil {
		t.Fatal(err)
	}

	for range 2 {
		if err := manager.Delete(context.Background(), "example.com"); err != nil {
			t.Fatal(err)
		}
	}

	if len(fake.records("example.com.")) != 0 {
		t.Fatal("the zone is still there")
	}
}

func TestLongTextIsSplitIntoQuotedStrings(t *testing.T) {
	fake, manager := newFakePowerDNS(t)
	value := `say "hi" \ ` + strings.Repeat("é", 200)

	zone := Zone{Name: "example.com", Nameservers: []string{"ns1.example.net"}, Records: []Record{
		{Name: "example.com", Type: "TXT", Content: value, TTL: 3600},
	}}

	if err := manager.Sync(context.Background(), zone); err != nil {
		t.Fatal(err)
	}

	var txt string

	for _, line := range fake.records("example.com.") {
		if strings.Contains(line, " TXT ") {
			txt = strings.SplitN(line, " ", 4)[3]
		}
	}

	parts := strings.Split(txt, `" "`)
	if len(parts) != 2 || !strings.HasPrefix(txt, `"say \"hi\" \\ `) {
		t.Fatalf("TXT = %s", txt)
	}

	joined := ""

	for _, part := range parts {
		unescaped := strings.NewReplacer(`\"`, `"`, `\\`, `\`).Replace(strings.Trim(part, `"`))
		if len(unescaped) > 255 {
			t.Fatalf("part of %d bytes: %q", len(unescaped), part)
		}

		joined += unescaped
	}

	if joined != value {
		t.Fatalf("the parts do not add up to the value: %q", joined)
	}
}

func TestInvalidZonesNeverReachPowerDNS(t *testing.T) {
	record := func(name, kind, content string) Zone {
		return Zone{Name: "example.com", Nameservers: []string{"ns1.example.net"}, Records: []Record{
			{Name: name, Type: kind, Content: content, TTL: 3600},
		}}
	}

	invalid := map[string]Zone{
		"bad zone":            {Name: "Example.com", Nameservers: []string{"ns1.example.net"}},
		"no nameservers":      {Name: "example.com"},
		"same nameserver":     {Name: "example.com", Nameservers: []string{"ns1.example.net", "ns1.example.net"}},
		"outside the zone":    record("example.org", "A", "203.0.113.1"),
		"suffix trick":        record("badexample.com", "A", "203.0.113.1"),
		"bad label":           record("we b.example.com", "A", "203.0.113.1"),
		"wildcard inside":     record("a.*.example.com", "A", "203.0.113.1"),
		"not ipv4":            record("example.com", "A", "2001:db8::1"),
		"not ipv6":            record("example.com", "AAAA", "203.0.113.1"),
		"cname at apex":       record("example.com", "CNAME", "other.example.net"),
		"ns at apex":          record("example.com", "NS", "ns9.example.net"),
		"soa":                 record("example.com", "SOA", "ns1. host. 1 2 3 4 5"),
		"unknown type":        record("example.com", "PTR", "host.example.net"),
		"bad host":            record("www.example.com", "CNAME", "bad host"),
		"single label target": record("www.example.com", "CNAME", "localhost"),
		"txt newline":         record("example.com", "TXT", "a\nb"),
		"txt empty":           record("example.com", "TXT", ""),
		"srv fields":          record("_x._tcp.example.com", "SRV", "5 sip.example.com"),
		"caa quote":           record("example.com", "CAA", `0 issue "x"`),
		"caa tag":             record("example.com", "CAA", "0 sign x.org"),
		"cname and more": {Name: "example.com", Nameservers: []string{"ns1.example.net"}, Records: []Record{
			{Name: "www.example.com", Type: "CNAME", Content: "example.com", TTL: 3600},
			{Name: "www.example.com", Type: "A", Content: "203.0.113.1", TTL: 3600},
		}},
		"ttl": {Name: "example.com", Nameservers: []string{"ns1.example.net"}, Records: []Record{
			{Name: "example.com", Type: "A", Content: "203.0.113.1", TTL: 10},
		}},
		"mx priority": {Name: "example.com", Nameservers: []string{"ns1.example.net"}, Records: []Record{
			{Name: "example.com", Type: "MX", Content: "mail.example.com", TTL: 3600, Priority: 70000},
		}},
	}

	fake, manager := newFakePowerDNS(t)

	for name, zone := range invalid {
		if err := manager.Sync(context.Background(), zone); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}

	if len(fake.requests) != 0 {
		t.Fatalf("invalid zones reached PowerDNS: %v", fake.requests)
	}
}

func TestPowerDNSErrorsAndAMissingKeyAreReported(t *testing.T) {
	fake, manager := newFakePowerDNS(t)
	fake.failWith = "Backend error"

	err := manager.Sync(context.Background(), exampleZone())
	if !errors.Is(err, ErrServer) || !strings.Contains(err.Error(), "Backend error") {
		t.Fatalf("err = %v", err)
	}

	manager.config.APIKeyFile = filepath.Join(t.TempDir(), "missing")

	if err := manager.Sync(context.Background(), exampleZone()); err == nil || !strings.Contains(err.Error(), "not set up") {
		t.Fatalf("err = %v", err)
	}
}

func TestChallengesGoInTheZoneThatHoldsTheName(t *testing.T) {
	fake, manager := newFakePowerDNS(t)
	ctx := context.Background()

	if err := manager.Sync(ctx, exampleZone()); err != nil {
		t.Fatal(err)
	}

	for _, step := range []struct{ domain, value string }{
		{"example.com", "domain-token"},
		{"example.com", "wildcard-token"},
		{"example.com", "wildcard-token"},
		{"blog.example.com", "blog-token"},
	} {
		if err := manager.AddChallenge(ctx, step.domain, step.value); err != nil {
			t.Fatal(err)
		}
	}

	challenges := func() []string {
		var lines []string

		for _, line := range fake.records("example.com.") {
			if strings.HasPrefix(line, "_acme-challenge.") {
				lines = append(lines, line)
			}
		}

		return lines
	}

	want := []string{
		`_acme-challenge.blog.example.com. TXT 60 "blog-token"`,
		`_acme-challenge.example.com. TXT 60 "domain-token"`,
		`_acme-challenge.example.com. TXT 60 "wildcard-token"`,
	}
	if got := challenges(); !slices.Equal(got, want) {
		t.Fatalf("challenges = %q", got)
	}

	if fake.count(http.MethodPut) != 4 {
		t.Fatalf("the cache was not flushed after every change: %v", fake.requests)
	}

	// The panel's sync leaves a running challenge alone.
	if err := manager.Sync(ctx, exampleZone()); err != nil {
		t.Fatal(err)
	}

	if got := challenges(); len(got) != 3 {
		t.Fatalf("a sync removed challenges: %q", got)
	}

	for _, step := range []struct{ domain, value string }{
		{"example.com", "domain-token"},
		{"example.com", "wildcard-token"},
		{"blog.example.com", "blog-token"},
		{"blog.example.com", "blog-token"},
	} {
		if err := manager.RemoveChallenge(ctx, step.domain, step.value); err != nil {
			t.Fatal(err)
		}
	}

	if got := challenges(); len(got) != 0 {
		t.Fatalf("challenges left = %q", got)
	}
}

func TestChallengesNeedAZoneOfThisServerAndAPlainValue(t *testing.T) {
	fake, manager := newFakePowerDNS(t)
	ctx := context.Background()

	if err := manager.AddChallenge(ctx, "example.org", "token"); err == nil || !strings.Contains(err.Error(), "no zone of this server holds example.org") {
		t.Fatalf("err = %v", err)
	}

	requests := len(fake.requests)

	for _, value := range []string{"", `to"ken`, "to ken", strings.Repeat("a", 256)} {
		if err := manager.AddChallenge(ctx, "example.org", value); err == nil {
			t.Errorf("value %q was accepted", value)
		}
	}

	if err := manager.AddChallenge(ctx, "Example.org", "token"); err == nil {
		t.Error("an uppercase domain was accepted")
	}

	if len(fake.requests) != requests {
		t.Fatalf("invalid challenges reached PowerDNS: %v", fake.requests[requests:])
	}
}
