// Package dns keeps the DNS zones of hosting accounts in PowerDNS, through
// PowerDNS's HTTP API on 127.0.0.1. The panel's database is the source of
// truth: a sync sends a zone's complete content and PowerDNS is made to
// match it, so syncing again changes nothing and the last sync wins.
//
// Every record is validated here again and put into PowerDNS's form:
// absolute names with a trailing dot, TXT values quoted (and split into
// strings of at most 255 bytes), priorities in front of MX and SRV content.
// The SOA and the zone's own NS records come from the nameservers given,
// never from the records.
package dns

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/netip"
	"os"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"rayxo/agent/internal/validate"
)

const (
	// MaxRecords is the most records one zone may have.
	MaxRecords = 1000
	// MaxNameservers is the most nameservers a zone may name.
	MaxNameservers = 4
	// MinTTL and MaxTTL bound a record's TTL, in seconds.
	MinTTL = 60
	MaxTTL = 604800
	// MaxTXT is the longest TXT value, in bytes (a 4096-bit DKIM key fits).
	MaxTXT = 4000

	// soaTimers are the SOA's refresh, retry, expire and negative-answer
	// TTL; zoneTTL is the TTL of the SOA and the zone's NS records.
	soaTimers = "10800 3600 604800 3600"
	zoneTTL   = 3600

	// requestTimeout bounds one call to the PowerDNS API.
	requestTimeout = 30 * time.Second
	// maxResponse is the most of a response body read.
	maxResponse = 16 << 20
)

// Types are the record types a zone may hold besides its SOA.
var Types = []string{"A", "AAAA", "CNAME", "MX", "TXT", "SRV", "CAA", "NS"}

var (
	// labelPattern is one label of a record name: letters, digits, dashes
	// and underscores (_dmarc, _domainkey), not starting or ending in a dash.
	labelPattern = regexp.MustCompile(`^[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?$`)
	// caaValuePattern is a CAA value: printable ASCII without spaces,
	// quotes or backslashes; the agent adds the quotes itself.
	caaValuePattern = regexp.MustCompile(`^[\x21\x23-\x5b\x5d-\x7e]{1,255}$`)
	caaTags         = []string{"issue", "issuewild", "iodef"}
)

// ErrServer means PowerDNS refused or failed a request; the error says how.
var ErrServer = errors.New("the DNS server could not do it")

// Record is one record of a zone, as the panel keeps it.
type Record struct {
	// Name is absolute without the trailing dot: the zone itself, a name
	// under it, or "*." and the zone or a name under it.
	Name string `json:"name"`
	Type string `json:"type"`
	// Content is the value in its usual form: an address for A and AAAA, a
	// host name for CNAME, MX and NS, the text itself (unquoted) for TXT,
	// "weight port target" for SRV and "flags tag value" (value unquoted)
	// for CAA.
	Content string `json:"content"`
	TTL     int    `json:"ttl"`
	// Priority is used by MX and SRV only.
	Priority int `json:"priority"`
}

// Zone is a zone's complete content.
type Zone struct {
	// Name is the zone, like example.com.
	Name string
	// Nameservers are the zone's NS records, the first also in its SOA.
	Nameservers []string
	Records     []Record
}

// Config says how to reach PowerDNS.
type Config struct {
	// ServerURL is the API of the PowerDNS server, up to and including
	// /servers/<id>.
	ServerURL string
	// APIKeyFile holds the API key, readable by root only.
	APIKeyFile string
	// Client makes the requests; nil uses one without proxies or redirects.
	Client *http.Client
}

// DefaultConfig returns the PowerDNS that setup.sh installs.
func DefaultConfig() Config {
	return Config{
		ServerURL:  "http://127.0.0.1:8081/api/v1/servers/localhost",
		APIKeyFile: "/etc/rayxo/pdns-api-key",
	}
}

// Manager syncs zones to PowerDNS. Callers make sure only one call changes
// a given zone at a time (the agent's account lock).
type Manager struct {
	config Config
	client *http.Client
}

// New returns a Manager for config.
func New(config Config) *Manager {
	client := config.Client
	if client == nil {
		client = &http.Client{
			Timeout:   requestTimeout,
			Transport: &http.Transport{Proxy: nil, ResponseHeaderTimeout: requestTimeout},
			CheckRedirect: func(*http.Request, []*http.Request) error {
				return http.ErrUseLastResponse
			},
		}
	}

	return &Manager{config: config, client: client}
}

// Validate returns an error unless zone can be synced as it is.
func Validate(zone Zone) error {
	if err := validate.Domain(zone.Name); err != nil {
		return err
	}

	if len(zone.Nameservers) == 0 || len(zone.Nameservers) > MaxNameservers {
		return fmt.Errorf("a zone needs 1 to %d nameservers", MaxNameservers)
	}

	for i, nameserver := range zone.Nameservers {
		if err := validate.Domain(nameserver); err != nil {
			return fmt.Errorf("nameserver: %w", err)
		}

		if slices.Contains(zone.Nameservers[:i], nameserver) {
			return fmt.Errorf("nameserver %q is given twice", nameserver)
		}
	}

	if len(zone.Records) > MaxRecords {
		return fmt.Errorf("a zone may have at most %d records", MaxRecords)
	}

	_, err := buildRRsets(zone)

	return err
}

// Sync makes PowerDNS hold exactly zone: the zone is created when it is
// missing, records that differ are replaced and records that are no longer
// wanted are removed, in one request. Nothing is sent when PowerDNS already
// matches, so the SOA serial only moves on a real change.
func (m *Manager) Sync(ctx context.Context, zone Zone) error {
	if err := Validate(zone); err != nil {
		return err
	}

	want, err := buildRRsets(zone)
	if err != nil {
		return err
	}

	current, found, err := m.getZone(ctx, zone.Name)
	if err != nil {
		return err
	}

	if !found {
		want = append(want, soaRRset(zone, 1))

		return m.send(ctx, http.MethodPost, "/zones", apiZone{
			Name:        absolute(zone.Name),
			Kind:        "Native",
			SOAEditAPI:  "DEFAULT",
			Nameservers: []string{},
			RRsets:      want,
		}, nil)
	}

	changes := diff(current, want, zone)
	if len(changes) == 0 {
		return nil
	}

	return m.send(ctx, http.MethodPatch, zonePath(zone.Name), apiZone{RRsets: changes}, nil)
}

// Delete removes the zone named name from PowerDNS. A zone that is not
// there is not an error.
func (m *Manager) Delete(ctx context.Context, name string) error {
	if err := validate.Domain(name); err != nil {
		return err
	}

	err := m.send(ctx, http.MethodDelete, zonePath(name), nil, nil)
	if errors.Is(err, errNotFound) {
		return nil
	}

	return err
}

// apiRecord, apiRRset and apiZone are PowerDNS's API objects.
type apiRecord struct {
	Content  string `json:"content"`
	Disabled bool   `json:"disabled"`
}

type apiRRset struct {
	Name       string      `json:"name"`
	Type       string      `json:"type"`
	TTL        int         `json:"ttl,omitempty"`
	ChangeType string      `json:"changetype,omitempty"`
	Records    []apiRecord `json:"records"`
}

type apiZone struct {
	Name       string `json:"name,omitempty"`
	Kind       string `json:"kind,omitempty"`
	SOAEditAPI string `json:"soa_edit_api,omitempty"`
	// Nameservers must be sent empty when the rrsets hold the NS records.
	Nameservers []string   `json:"nameservers,omitempty"`
	RRsets      []apiRRset `json:"rrsets"`
}

// errNotFound is PowerDNS's answer for a zone it does not have.
var errNotFound = errors.New("zone not found")

// buildRRsets turns the zone's records, and its nameservers, into rrsets
// in PowerDNS's form, sorted. The SOA is not among them.
func buildRRsets(zone Zone) ([]apiRRset, error) {
	type key struct{ name, kind string }

	ttls := map[key]int{}
	contents := map[key][]string{}
	types := map[string][]string{}

	for _, record := range zone.Records {
		content, err := normalize(zone.Name, record)
		if err != nil {
			return nil, err
		}

		k := key{absolute(record.Name), record.Type}
		if ttl, ok := ttls[k]; !ok || record.TTL < ttl {
			ttls[k] = record.TTL
		}

		if !slices.Contains(contents[k], content) {
			contents[k] = append(contents[k], content)
		}

		if !slices.Contains(types[k.name], record.Type) {
			types[k.name] = append(types[k.name], record.Type)
		}
	}

	for name, kinds := range types {
		if slices.Contains(kinds, "CNAME") && (len(kinds) > 1 || len(contents[key{name, "CNAME"}]) > 1) {
			return nil, fmt.Errorf("%s has a CNAME, so it can have no other record", strings.TrimSuffix(name, "."))
		}
	}

	nameservers := make([]string, 0, len(zone.Nameservers))
	for _, nameserver := range zone.Nameservers {
		nameservers = append(nameservers, absolute(nameserver))
	}

	apex := key{absolute(zone.Name), "NS"}
	contents[apex] = nameservers
	ttls[apex] = zoneTTL

	rrsets := make([]apiRRset, 0, len(contents))
	for k, values := range contents {
		slices.Sort(values)

		records := make([]apiRecord, 0, len(values))
		for _, value := range values {
			records = append(records, apiRecord{Content: value})
		}

		rrsets = append(rrsets, apiRRset{Name: k.name, Type: k.kind, TTL: ttls[k], Records: records})
	}

	slices.SortFunc(rrsets, func(a, b apiRRset) int {
		return strings.Compare(a.Name+" "+a.Type, b.Name+" "+b.Type)
	})

	return rrsets, nil
}

// normalize validates record, which belongs to zone, and returns its
// content in PowerDNS's form.
func normalize(zone string, record Record) (string, error) {
	if err := checkName(zone, record.Name); err != nil {
		return "", err
	}

	fail := func(format string, args ...any) (string, error) {
		return "", fmt.Errorf("%s %s: "+format, append([]any{record.Name, record.Type}, args...)...)
	}

	if record.TTL < MinTTL || record.TTL > MaxTTL {
		return fail("the TTL must be %d to %d seconds", MinTTL, MaxTTL)
	}

	isApex := record.Name == zone
	content := record.Content

	switch record.Type {
	case "A":
		address, err := netip.ParseAddr(content)
		if err != nil || !address.Is4() {
			return fail("%q is not an IPv4 address", content)
		}

		return address.String(), nil
	case "AAAA":
		address, err := netip.ParseAddr(content)
		if err != nil || !address.Is6() || address.Is4In6() || address.Zone() != "" {
			return fail("%q is not an IPv6 address", content)
		}

		return address.String(), nil
	case "CNAME":
		if isApex {
			return fail("the zone itself cannot be a CNAME")
		}

		return host(content, fail)
	case "NS":
		if isApex {
			return fail("the zone's own nameservers come from its nameserver settings")
		}

		return host(content, fail)
	case "MX":
		if err := checkNumber(record.Priority); err != nil {
			return fail("priority %v", err)
		}

		target, err := host(content, fail)
		if err != nil {
			return "", err
		}

		return strconv.Itoa(record.Priority) + " " + target, nil
	case "SRV":
		if err := checkNumber(record.Priority); err != nil {
			return fail("priority %v", err)
		}

		fields := strings.Split(content, " ")
		if len(fields) != 3 {
			return fail("%q must be \"weight port target\"", content)
		}

		for _, field := range fields[:2] {
			n, err := strconv.Atoi(field)
			if err != nil || checkNumber(n) != nil || strconv.Itoa(n) != field {
				return fail("weight and port must be 0 to 65535")
			}
		}

		target, err := host(fields[2], fail)
		if err != nil {
			return "", err
		}

		return strconv.Itoa(record.Priority) + " " + fields[0] + " " + fields[1] + " " + target, nil
	case "TXT":
		return quoteTXT(content, fail)
	case "CAA":
		fields := strings.SplitN(content, " ", 3)
		if len(fields) != 3 {
			return fail("%q must be \"flags tag value\"", content)
		}

		flags, err := strconv.Atoi(fields[0])
		if err != nil || flags < 0 || flags > 255 || strconv.Itoa(flags) != fields[0] {
			return fail("flags must be 0 to 255")
		}

		if !slices.Contains(caaTags, fields[1]) {
			return fail("the tag must be one of %s", strings.Join(caaTags, ", "))
		}

		if !caaValuePattern.MatchString(fields[2]) {
			return fail("the value must be printable characters without spaces, quotes or backslashes")
		}

		return fields[0] + " " + fields[1] + " \"" + fields[2] + "\"", nil
	case "SOA":
		return fail("the SOA comes from the zone's nameserver settings")
	}

	return fail("the type must be one of %s", strings.Join(Types, ", "))
}

// checkName returns an error unless name is zone, under zone, or a
// wildcard there.
func checkName(zone, name string) error {
	if name == zone {
		return nil
	}

	if !strings.HasSuffix(name, "."+zone) {
		return fmt.Errorf("record name %q is not in zone %s", name, zone)
	}

	if len(name) > 253 {
		return fmt.Errorf("record name %q is longer than 253 characters", name)
	}

	for i, label := range strings.Split(strings.TrimSuffix(name, "."+zone), ".") {
		if label == "*" && i == 0 {
			continue
		}

		if !labelPattern.MatchString(label) {
			return fmt.Errorf("record name %q has an invalid label %q", name, label)
		}
	}

	return nil
}

// host validates a target host name and returns it absolute.
func host(name string, fail func(string, ...any) (string, error)) (string, error) {
	name = strings.TrimSuffix(name, ".")

	labels := strings.Split(name, ".")
	if name == "" || len(name) > 253 || len(labels) < 2 {
		return fail("%q is not a host name", name)
	}

	for _, label := range labels {
		if !labelPattern.MatchString(label) {
			return fail("%q is not a host name", name)
		}
	}

	return name + ".", nil
}

// quoteTXT checks a TXT value and quotes it, split into strings of at most
// 255 bytes, never inside a character.
func quoteTXT(value string, fail func(string, ...any) (string, error)) (string, error) {
	if value == "" || len(value) > MaxTXT || !utf8.ValidString(value) {
		return fail("the text must be 1 to %d bytes of UTF-8", MaxTXT)
	}

	if strings.ContainsFunc(value, func(r rune) bool { return r < 0x20 || r == 0x7f }) {
		return fail("the text must not hold control characters or line breaks")
	}

	escape := strings.NewReplacer(`\`, `\\`, `"`, `\"`)

	var quoted, chunk strings.Builder

	flush := func() {
		if quoted.Len() > 0 {
			quoted.WriteByte(' ')
		}

		quoted.WriteString(`"` + escape.Replace(chunk.String()) + `"`)
		chunk.Reset()
	}

	for _, r := range value {
		if chunk.Len()+utf8.RuneLen(r) > 255 {
			flush()
		}

		chunk.WriteRune(r)
	}

	flush()

	return quoted.String(), nil
}

// checkNumber returns an error unless n fits 16 bits.
func checkNumber(n int) error {
	if n < 0 || n > 65535 {
		return errors.New("must be 0 to 65535")
	}

	return nil
}

// soaRRset is the zone's SOA with serial.
func soaRRset(zone Zone, serial uint64) apiRRset {
	content := fmt.Sprintf("%s hostmaster.%s %d %s", absolute(zone.Nameservers[0]), absolute(zone.Name), serial, soaTimers)

	return apiRRset{Name: absolute(zone.Name), Type: "SOA", TTL: zoneTTL, Records: []apiRecord{{Content: content}}}
}

// diff returns the changes that make current hold want: rrsets replaced or
// deleted, and the SOA when anything but its serial differs (its serial
// then moves up by one; PowerDNS also moves it on every other change).
func diff(current, want []apiRRset, zone Zone) []apiRRset {
	var changes []apiRRset

	have := map[string]apiRRset{}

	var soa *apiRRset

	for _, rrset := range current {
		if rrset.Type == "SOA" {
			soa = &rrset

			continue
		}

		have[rrset.Name+" "+rrset.Type] = rrset
	}

	for _, rrset := range want {
		if old, ok := have[rrset.Name+" "+rrset.Type]; !ok || !same(old, rrset) {
			rrset.ChangeType = "REPLACE"
			changes = append(changes, rrset)
		}

		delete(have, rrset.Name+" "+rrset.Type)
	}

	for _, rrset := range have {
		changes = append(changes, apiRRset{Name: rrset.Name, Type: rrset.Type, ChangeType: "DELETE", Records: []apiRecord{}})
	}

	wantSOA := soaRRset(zone, 0)
	if soa == nil || len(soa.Records) != 1 || soa.TTL != wantSOA.TTL ||
		withoutSerial(soa.Records[0].Content) != withoutSerial(wantSOA.Records[0].Content) {
		serial := uint64(1)

		if soa != nil && len(soa.Records) == 1 {
			if fields := strings.Fields(soa.Records[0].Content); len(fields) == 7 {
				if n, err := strconv.ParseUint(fields[2], 10, 32); err == nil {
					serial = n + 1
				}
			}
		}

		replace := soaRRset(zone, serial)
		replace.ChangeType = "REPLACE"
		changes = append(changes, replace)
	}

	slices.SortFunc(changes, func(a, b apiRRset) int {
		return strings.Compare(a.Name+" "+a.Type, b.Name+" "+b.Type)
	})

	return changes
}

// same reports whether two rrsets hold the same records and TTL.
func same(a, b apiRRset) bool {
	if a.TTL != b.TTL || len(a.Records) != len(b.Records) {
		return false
	}

	values := func(rrset apiRRset) []string {
		out := make([]string, 0, len(rrset.Records))
		for _, record := range rrset.Records {
			if record.Disabled {
				out = append(out, "disabled:"+record.Content)
			} else {
				out = append(out, record.Content)
			}
		}

		slices.Sort(out)

		return out
	}

	return slices.Equal(values(a), values(b))
}

// withoutSerial is an SOA content without its serial.
func withoutSerial(content string) string {
	fields := strings.Fields(content)
	if len(fields) == 7 {
		fields[2] = ""
	}

	return strings.Join(fields, " ")
}

// getZone returns the zone's rrsets, and whether PowerDNS has the zone.
func (m *Manager) getZone(ctx context.Context, name string) ([]apiRRset, bool, error) {
	var zone apiZone

	err := m.send(ctx, http.MethodGet, zonePath(name), nil, &zone)
	if errors.Is(err, errNotFound) {
		return nil, false, nil
	}

	if err != nil {
		return nil, false, err
	}

	return zone.RRsets, true, nil
}

// send makes one API request, with body as JSON, and decodes the answer
// into out when out is not nil.
func (m *Manager) send(ctx context.Context, method, path string, body, out any) error {
	key, err := os.ReadFile(m.config.APIKeyFile)
	if err != nil {
		return fmt.Errorf("the DNS server is not set up (reading its API key: %w)", err)
	}

	var reader io.Reader

	if body != nil {
		encoded, err := json.Marshal(body)
		if err != nil {
			return err
		}

		reader = bytes.NewReader(encoded)
	}

	ctx, cancel := context.WithTimeout(ctx, requestTimeout)
	defer cancel()

	request, err := http.NewRequestWithContext(ctx, method, m.config.ServerURL+path, reader)
	if err != nil {
		return err
	}

	request.Header.Set("X-API-Key", strings.TrimSpace(string(key)))
	request.Header.Set("Accept", "application/json")

	if body != nil {
		request.Header.Set("Content-Type", "application/json")
	}

	response, err := m.client.Do(request)
	if err != nil {
		return fmt.Errorf("%w: %v", ErrServer, err)
	}
	defer response.Body.Close()

	answer, err := io.ReadAll(io.LimitReader(response.Body, maxResponse))
	if err != nil {
		return fmt.Errorf("%w: %v", ErrServer, err)
	}

	if response.StatusCode == http.StatusNotFound ||
		(response.StatusCode == http.StatusUnprocessableEntity && bytes.Contains(answer, []byte("Could not find domain"))) {
		return errNotFound
	}

	if response.StatusCode < 200 || response.StatusCode > 299 {
		var failure struct {
			Error string `json:"error"`
		}

		_ = json.Unmarshal(answer, &failure)

		return fmt.Errorf("%w: %s %s answered %d %s", ErrServer, method, path, response.StatusCode, failure.Error)
	}

	if out == nil || len(answer) == 0 {
		return nil
	}

	if err := json.Unmarshal(answer, out); err != nil {
		return fmt.Errorf("%w: unreadable answer: %v", ErrServer, err)
	}

	return nil
}

// absolute is name with a trailing dot.
func absolute(name string) string {
	return name + "."
}

// zonePath is the API path of the zone named name. Valid zone names hold
// only letters, digits, dots and dashes, which PowerDNS keeps as they are in
// zone ids.
func zonePath(name string) string {
	return "/zones/" + absolute(name)
}
