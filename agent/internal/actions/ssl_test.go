package actions

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"encoding/pem"
	"errors"
	"math/big"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/runner"
)

// certificateExpiry is when every certificate the fake certbot issues
// expires.
var certificateExpiry = time.Date(2027, 1, 2, 3, 4, 5, 0, time.UTC)

// dial sends the reachability check's connections for the names in
// e.reachable to their address; other names have no DNS record.
func (e *webEnv) dial(ctx context.Context, network, address string) (net.Conn, error) {
	host, _, err := net.SplitHostPort(address)
	if err != nil {
		return nil, err
	}

	e.mu.Lock()
	target, ok := e.reachable[host]
	e.mu.Unlock()

	if !ok {
		return nil, &net.DNSError{Err: "no such host", Name: host, IsNotFound: true}
	}

	return (&net.Dialer{}).DialContext(ctx, network, target)
}

// reach makes names reach this server's ACME webroot, as every port 80
// vhost serves it.
func (e *webEnv) reach(names ...string) {
	e.t.Helper()

	server := httptest.NewServer(http.FileServer(http.Dir(e.cfg.AcmeDir)))
	e.t.Cleanup(server.Close)

	e.mu.Lock()
	defer e.mu.Unlock()

	for _, name := range names {
		e.reachable[name] = server.Listener.Addr().String()
	}
}

// fakeCertbot issues and deletes certificates where certbot keeps them.
// It runs with e.mu held.
func (e *webEnv) fakeCertbot(args []string) ([]byte, error) {
	name := args[slices.Index(args, "--cert-name")+1]
	live := filepath.Join(e.cfg.LetsEncryptDir, "live", name)
	renewal := filepath.Join(e.cfg.LetsEncryptDir, "renewal", name+".conf")

	if args[0] == "delete" {
		return nil, errors.Join(os.RemoveAll(live), os.Remove(renewal))
	}

	var names []string

	for i, arg := range args {
		if arg == "-d" {
			names = append(names, args[i+1])
		}
	}

	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, err
	}

	template := &x509.Certificate{
		SerialNumber: big.NewInt(1),
		Subject:      pkix.Name{CommonName: names[0]},
		DNSNames:     names,
		NotBefore:    certificateExpiry.AddDate(0, -3, 0),
		NotAfter:     certificateExpiry,
	}

	der, err := x509.CreateCertificate(rand.Reader, template, template, &key.PublicKey, key)
	if err != nil {
		return nil, err
	}

	if err := os.MkdirAll(live, 0o755); err != nil {
		return nil, err
	}

	return nil, errors.Join(
		os.WriteFile(filepath.Join(live, "fullchain.pem"), pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), 0o644),
		os.WriteFile(filepath.Join(live, "privkey.pem"), []byte("key"), 0o600),
		os.MkdirAll(filepath.Dir(renewal), 0o755),
		os.WriteFile(renewal, []byte("version = 2.9.0\n"), 0o644),
	)
}

// failCertbot makes certbot exit with code 1 after printing output.
func (e *webEnv) failCertbot(output string) {
	e.certbot = func([]string) ([]byte, error) {
		return []byte(output), &runner.Error{Program: "/usr/bin/certbot", ExitCode: 1, Output: output, Err: errors.New("exit status 1")}
	}
}

func domainOf(username, domain string) map[string]string {
	return map[string]string{"username": username, "domain": domain}
}

func (e *webEnv) mustCall(method string, params any) string {
	e.t.Helper()

	result, err := e.call(method, params)
	if err != nil {
		e.t.Fatalf("%s failed: %v", method, err)
	}

	return string(result)
}

func (e *webEnv) assertMessage(method string, params any, want string) {
	e.t.Helper()

	_, err := e.call(method, params)

	agentErr := assertCode(e.t, err, rpc.CodeActionFailed)
	if agentErr.Message != want {
		e.t.Fatalf("%s: message\n  %q\nwant\n  %q", method, agentErr.Message, want)
	}
}

func (e *webEnv) countPrograms(command string) int {
	e.mu.Lock()
	defer e.mu.Unlock()

	count := 0

	for _, program := range e.programs {
		if program == command {
			count++
		}
	}

	return count
}

func TestCertificateLifecycle(t *testing.T) {
	env := newWebEnv(t)
	env.mustCall("web.apply_domain", applyParams("example.com", "8.4"))
	env.reach("example.com", "www.example.com")

	result := env.mustCall("ssl.issue", domainOf("budi", "example.com"))
	if result != `{"names":["example.com","www.example.com"],"expires_at":"2027-01-02T03:04:05Z"}` {
		t.Fatalf("unexpected result %s", result)
	}

	// HTTPS on, with HTTP redirected; the result is unchanged.
	params := map[string]any{"username": "budi", "domain": "example.com", "php_version": "8.4", "ssl": true, "force_https": true}
	if result := env.mustCall("web.apply_domain", params); result != `{"domain":"example.com","document_root":"/home/budi/domains/example.com/public_html"}` {
		t.Fatalf("unexpected apply result %s", result)
	}

	vhost, err := os.ReadFile(env.cfg.NginxDir + "/budi/example.com.conf")
	if err != nil {
		t.Fatal(err)
	}

	for _, want := range []string{"listen 443 ssl http2;", env.cfg.LetsEncryptDir + "/live/example.com/fullchain.pem", "return 301 https://$host$request_uri;"} {
		if !strings.Contains(string(vhost), want) {
			t.Fatalf("vhost does not contain %q:\n%s", want, vhost)
		}
	}

	env.assertMessage("ssl.delete", domainOf("budi", "example.com"), "turn off HTTPS for example.com before deleting its certificate")

	if env.countPrograms("certbot delete") != 0 {
		t.Fatal("certbot ran for a certificate in use")
	}

	env.mustCall("web.apply_domain", applyParams("example.com", "8.4"))

	if result := env.mustCall("ssl.delete", domainOf("budi", "example.com")); result != `{}` {
		t.Fatalf("unexpected delete result %s", result)
	}

	if env.countPrograms("certbot delete") != 1 {
		t.Fatalf("certbot delete did not run: %v", env.programs)
	}

	// Deleting again succeeds without certbot.
	env.mustCall("ssl.delete", domainOf("budi", "example.com"))

	if env.countPrograms("certbot delete") != 1 {
		t.Fatal("certbot ran for a missing certificate")
	}
}

func TestApplyDomainWithSSLNeedsACertificate(t *testing.T) {
	env := newWebEnv(t)
	params := map[string]any{"username": "budi", "domain": "example.com", "php_version": "8.4", "ssl": true}

	env.assertMessage("web.apply_domain", params, "there is no certificate for example.com yet; request one first")
	env.assertUntouched("lookup budi")
}

func TestSSLMethodsNeedTheDomainOnThisAccount(t *testing.T) {
	env := newWebEnv(t)
	env.mustCall("web.apply_domain", map[string]string{"username": "sari", "domain": "example.com", "php_version": "8.4"})

	for _, method := range []string{"ssl.issue", "ssl.delete"} {
		env.assertMessage(method, domainOf("budi", "example.com"), "example.com is not set up on this account")
	}

	if env.countPrograms("certbot certonly")+env.countPrograms("certbot delete") != 0 {
		t.Fatal("certbot ran")
	}
}

func TestIssueCertificateExplainsAnUnreachableDomain(t *testing.T) {
	env := newWebEnv(t)
	env.mustCall("web.apply_domain", applyParams("example.com", "8.4"))

	env.assertMessage("ssl.issue", domainOf("budi", "example.com"),
		"example.com does not reach this server over HTTP (no DNS record); point its A record to this server, "+
			"or proxy it through Cloudflare, then try again")

	if env.countPrograms("certbot certonly") != 0 {
		t.Fatal("certbot ran for an unreachable domain")
	}

	// Without www, the certificate covers the domain alone.
	env.reach("example.com")

	if result := env.mustCall("ssl.issue", domainOf("budi", "example.com")); !strings.HasPrefix(result, `{"names":["example.com"],`) {
		t.Fatalf("unexpected result %s", result)
	}
}

func TestIssueCertificateExplainsCertbotFailures(t *testing.T) {
	failures := map[string]string{
		"Another instance of Certbot is already running.\n": "another certificate operation is running; try again in a minute",
		"An unexpected error occurred:\nurn:ietf:params:acme:error:rateLimited :: There were too many requests of a given type :: " +
			"Error creating new order :: too many certificates (5) already issued for this exact set of identifiers in the last 168h0m0s, " +
			"retry after 2026-11-09 05:28:43 UTC: see https://letsencrypt.org/docs/rate-limits/\n": "Let's Encrypt rate limit reached for example.com " +
			"(too many certificates (5) already issued for this exact set of identifiers in the last 168h0m0s, retry after 2026-11-09 05:28:43 UTC); try again later",
		"  Domain: example.com\n  Type:   dns\n  Detail: DNS problem: NXDOMAIN looking up A for example.com\n": "Let's Encrypt could not issue " +
			"a certificate for example.com: DNS problem: NXDOMAIN looking up A for example.com",
		"Traceback (most recent call last):\n": "Let's Encrypt could not issue a certificate for example.com; the agent log has the details",
	}

	for output, message := range failures {
		env := newWebEnv(t)
		env.mustCall("web.apply_domain", applyParams("example.com", "8.4"))
		env.reach("example.com")
		env.failCertbot(output)

		env.assertMessage("ssl.issue", domainOf("budi", "example.com"), message)
	}

	// certbot that cannot even start is an internal error.
	env := newWebEnv(t)
	env.mustCall("web.apply_domain", applyParams("example.com", "8.4"))
	env.reach("example.com")
	env.certbot = func([]string) ([]byte, error) {
		return nil, &runner.Error{Program: "/usr/bin/certbot", ExitCode: -1, Err: os.ErrNotExist}
	}

	_, err := env.call("ssl.issue", domainOf("budi", "example.com"))
	if _, ok := errors.AsType[*rpc.Error](err); ok || err == nil {
		t.Fatalf("got %v, want an internal error", err)
	}
}

func TestRemoveDomainDeletesTheCertificate(t *testing.T) {
	env := newWebEnv(t)
	env.mustCall("web.apply_domain", applyParams("example.com", "8.4"))
	env.reach("example.com")
	env.mustCall("ssl.issue", domainOf("budi", "example.com"))
	env.mustCall("web.apply_domain", map[string]any{"username": "budi", "domain": "example.com", "php_version": "8.4", "ssl": true})

	env.mustCall("web.remove_domain", domainOf("budi", "example.com"))

	if env.countPrograms("certbot delete") != 1 {
		t.Fatalf("certbot delete did not run: %v", env.programs)
	}

	if _, err := os.Stat(env.cfg.LetsEncryptDir + "/renewal/example.com.conf"); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("the certificate still exists (%v)", err)
	}
}

// selfSignedCertificate returns a PEM certificate for names, valid now,
// and its PEM private key.
func selfSignedCertificate(t *testing.T, names ...string) (string, string) {
	t.Helper()

	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}

	template := &x509.Certificate{
		SerialNumber: big.NewInt(1),
		Subject:      pkix.Name{CommonName: names[0]},
		DNSNames:     names,
		NotBefore:    time.Now().Add(-time.Hour),
		NotAfter:     time.Now().AddDate(1, 0, 0),
	}

	der, err := x509.CreateCertificate(rand.Reader, template, template, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}

	keyDER, err := x509.MarshalPKCS8PrivateKey(key)
	if err != nil {
		t.Fatal(err)
	}

	return string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der})),
		string(pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: keyDER}))
}

func TestInstallCertificateInstallsACustomCertificate(t *testing.T) {
	env := newWebEnv(t)

	if _, err := env.call("web.apply_domain", applyParams("example.com", "8.4")); err != nil {
		t.Fatalf("apply failed: %v", err)
	}

	certificate, key := selfSignedCertificate(t, "example.com", "www.example.com")

	result, err := env.call("ssl.install", map[string]string{
		"username": "budi", "domain": "example.com", "certificate": certificate, "private_key": key,
	})
	if err != nil {
		t.Fatalf("install failed: %v", err)
	}

	var info struct {
		Names     []string `json:"names"`
		ExpiresAt string   `json:"expires_at"`
		Issuer    string   `json:"issuer"`
		Trusted   bool     `json:"trusted"`
	}

	if err := json.Unmarshal(result, &info); err != nil {
		t.Fatal(err)
	}

	if !slices.Equal(info.Names, []string{"example.com", "www.example.com"}) || info.Issuer != "example.com" || info.Trusted {
		t.Fatalf("unexpected result %s", result)
	}

	if _, err := time.Parse(time.RFC3339, info.ExpiresAt); err != nil {
		t.Fatalf("expires_at %q: %v", info.ExpiresAt, err)
	}

	// HTTPS with it.
	params := map[string]any{"username": "budi", "domain": "example.com", "php_version": "8.4", "ssl": true, "certificate": "custom"}
	if _, err := env.call("web.apply_domain", params); err != nil {
		t.Fatalf("apply with the custom certificate failed: %v", err)
	}

	nginx, err := os.ReadFile(env.cfg.NginxDir + "/budi/example.com.conf")
	if err != nil || !strings.Contains(string(nginx), "ssl_certificate "+env.cfg.CustomCertificateDir+"/example.com/fullchain.pem;") {
		t.Fatalf("the vhost does not use the custom certificate (%v):\n%s", err, nginx)
	}
}

func TestInstallCertificateExplainsARefusal(t *testing.T) {
	env := newWebEnv(t)

	if _, err := env.call("web.apply_domain", applyParams("example.com", "8.4")); err != nil {
		t.Fatalf("apply failed: %v", err)
	}

	certificate, key := selfSignedCertificate(t, "example.org")

	_, err := env.call("ssl.install", map[string]string{
		"username": "budi", "domain": "example.com", "certificate": certificate, "private_key": key,
	})

	agentErr := assertCode(t, err, rpc.CodeActionFailed)
	if agentErr.Message != "the certificate does not cover example.com" {
		t.Fatalf("message = %q", agentErr.Message)
	}

	for _, params := range []map[string]any{
		{"username": "budi", "domain": "example.com", "certificate": certificate},
		{"username": "budi", "domain": "example.com", "certificate": "", "private_key": key},
		{"username": "budi", "domain": "../etc", "certificate": certificate, "private_key": key},
		{"username": "budi", "domain": "example.com", "certificate": certificate, "private_key": key, "chain": "x"},
		{"username": "budi", "domain": "example.com", "php_version": "8.4", "ssl": true, "certificate": "acme"},
	} {
		method := "ssl.install"
		if _, ok := params["php_version"]; ok {
			method = "web.apply_domain"
		}

		_, err := env.call(method, params)
		assertCode(t, err, rpc.CodeInvalidParams)
	}
}

func wildcardOf(domain string) map[string]any {
	return map[string]any{"username": "budi", "domain": domain, "wildcard": true, "nameservers": []string{"ns1.example.net", "ns2.example.net"}}
}

func TestWildcardCertificateNeedsThisServersNameservers(t *testing.T) {
	env := newWebEnv(t)
	env.mustCall("web.apply_domain", applyParams("example.com", "8.4"))

	var certbotArgs []string

	env.certbot = func(args []string) ([]byte, error) {
		certbotArgs = args

		return env.fakeCertbot(args)
	}

	env.nameservers["example.com"] = []string{"ns1.other.org", "ns2.other.org"}
	env.assertMessage("ssl.issue", wildcardOf("example.com"),
		"example.com uses nameservers ns1.other.org, ns2.other.org, not this server's; a wildcard certificate needs the domain to use "+
			"this server's nameservers: change them at the domain's registrar, wait until they are in use, then try again")

	if env.countPrograms("certbot certonly") != 0 {
		t.Fatal("certbot ran for a domain that is not on this server's nameservers")
	}

	env.nameservers["example.com"] = []string{"ns2.example.net"}

	if result := env.mustCall("ssl.issue", wildcardOf("example.com")); !strings.HasPrefix(result, `{"names":["example.com","*.example.com"],`) {
		t.Fatalf("unexpected result %s", result)
	}

	want := []string{
		"certonly", "--manual", "--preferred-challenges", "dns",
		"--manual-auth-hook", "/usr/local/rayxo/bin/rayxo acme-dns-hook auth",
		"--manual-cleanup-hook", "/usr/local/rayxo/bin/rayxo acme-dns-hook cleanup",
		"--cert-name", "example.com", "-d", "example.com", "-d", "*.example.com",
		"--non-interactive", "--agree-tos", "--keep-until-expiring", "--register-unsafely-without-email",
	}
	if !slices.Equal(certbotArgs, want) {
		t.Fatalf("certbot args = %q", certbotArgs)
	}
}

func TestSubdomainsServeHTTPSWithTheirDomainsWildcard(t *testing.T) {
	env := newWebEnv(t)
	env.mustCall("web.apply_domain", applyParams("example.com", "8.4"))
	env.nameservers["example.com"] = []string{"ns1.example.net"}
	env.mustCall("ssl.issue", wildcardOf("example.com"))

	blog := map[string]any{
		"username": "budi", "domain": "blog.example.com", "php_version": "8.4", "ssl": true,
		"certificate": "parent", "certificate_domain": "example.com",
	}
	env.mustCall("web.apply_domain", blog)

	vhost, err := os.ReadFile(filepath.Join(env.cfg.NginxDir, "budi", "blog.example.com.conf"))
	if err != nil {
		t.Fatal(err)
	}

	if !strings.Contains(string(vhost), "ssl_certificate "+filepath.Join(env.cfg.LetsEncryptDir, "live", "example.com", "fullchain.pem")+";") {
		t.Fatalf("the subdomain does not use its domain's certificate:\n%s", vhost)
	}

	// The domain's certificate stays while the subdomain uses it.
	env.assertMessage("ssl.delete", domainOf("budi", "example.com"), "turn off HTTPS for example.com before deleting its certificate")
}

func TestOnlyAWildcardOfTheSameAccountOneLabelUpServesASubdomain(t *testing.T) {
	env := newWebEnv(t)
	env.mustCall("web.apply_domain", applyParams("example.com", "8.4"))
	env.reach("example.com")
	env.mustCall("ssl.issue", domainOf("budi", "example.com"))

	parent := func(domain, certificateDomain string) map[string]any {
		return map[string]any{
			"username": "budi", "domain": domain, "php_version": "8.4", "ssl": true,
			"certificate": "parent", "certificate_domain": certificateDomain,
		}
	}

	// Without the wildcard, the domain's certificate does not cover it.
	env.assertMessage("web.apply_domain", parent("blog.example.com", "example.com"), "there is no certificate for blog.example.com yet; request one first")

	for _, params := range []any{
		parent("a.blog.example.com", "example.com"),
		parent("blog.example.org", "example.com"),
		map[string]any{"username": "budi", "domain": "blog.example.com", "php_version": "8.4", "ssl": true, "certificate": "parent"},
		map[string]any{"username": "budi", "domain": "blog.example.com", "php_version": "8.4", "ssl": true, "certificate_domain": "example.com"},
		map[string]any{"username": "budi", "domain": "blog.example.com", "php_version": "8.4", "certificate": "parent", "certificate_domain": "example.com"},
		map[string]any{"username": "budi", "domain": "example.com", "wildcard": true},
		map[string]any{"username": "budi", "domain": "example.com", "nameservers": []string{"ns1.example.net"}},
	} {
		method := "web.apply_domain"
		if _, ok := params.(map[string]any)["php_version"]; !ok {
			method = "ssl.issue"
		}

		_, err := env.call(method, params)
		if rpcErr, ok := errors.AsType[*rpc.Error](err); !ok || rpcErr.Code != rpc.CodeInvalidParams {
			t.Errorf("%v: got %v, want invalid params", params, err)
		}
	}

	// Another account's domain is not this account's to borrow from.
	env.mustCall("web.apply_domain", map[string]string{"username": "sari", "domain": "other.org", "php_version": "8.4"})

	if _, err := env.call("web.apply_domain", parent("blog.other.org", "other.org")); err == nil {
		t.Fatal("a subdomain used another account's certificate")
	}
}
