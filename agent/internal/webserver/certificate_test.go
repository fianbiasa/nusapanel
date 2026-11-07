package webserver

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"errors"
	"math/big"
	"net"
	"net/http"
	"net/http/httptest"
	"net/netip"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	"nusapanel/agent/internal/runner"
)

// testExpiry is when every certificate the fake certbot issues expires.
var testExpiry = time.Date(2027, 1, 2, 3, 4, 5, 0, time.UTC)

// dial is the reachability check's DialHTTP: it connects to the address
// e.hosts gives a name, returns the error given instead, or fails the way
// a name without a DNS record does.
func (e *testEnv) dial(ctx context.Context, network, address string) (net.Conn, error) {
	host, _, err := net.SplitHostPort(address)
	if err != nil {
		return nil, err
	}

	e.mu.Lock()
	target, ok := e.hosts[host]
	e.mu.Unlock()

	switch target := target.(type) {
	case string:
		return (&net.Dialer{}).DialContext(ctx, network, target)
	case error:
		return nil, &net.OpError{Op: "dial", Net: network, Err: target}
	}

	if !ok {
		return nil, &net.DNSError{Err: "no such host", Name: host, IsNotFound: true}
	}

	e.t.Fatalf("unexpected host target %v", target)

	return nil, nil
}

// reach makes names reach handler, or this server's ACME webroot (the way
// every port 80 vhost serves it) when handler is nil.
func (e *testEnv) reach(handler http.Handler, names ...string) {
	e.t.Helper()

	if handler == nil {
		handler = http.FileServer(http.Dir(e.cfg.AcmeDir))
	}

	server := httptest.NewServer(handler)
	e.t.Cleanup(server.Close)

	e.mu.Lock()
	defer e.mu.Unlock()

	for _, name := range names {
		e.hosts[name] = server.Listener.Addr().String()
	}
}

// fakeCertbot issues and deletes certificates the way certbot lays them out.
func (e *testEnv) fakeCertbot(args []string) ([]byte, error) {
	name := argument(args, "--cert-name")

	switch args[0] {
	case "certonly":
		var names []string

		for i, arg := range args {
			if arg == "-d" {
				names = append(names, args[i+1])
			}
		}

		e.writeCertificate(name, names...)
	case "delete":
		_ = os.RemoveAll(filepath.Join(e.cfg.LetsEncryptDir, "live", name))
		_ = os.Remove(filepath.Join(e.cfg.LetsEncryptDir, "renewal", name+".conf"))
	}

	return []byte("Saving debug log to /var/log/letsencrypt/letsencrypt.log\n"), nil
}

// certbotFailing returns a fake certbot that exits with code 1 after
// printing output.
func certbotFailing(output string) func([]string) ([]byte, error) {
	return func([]string) ([]byte, error) {
		return []byte(output), &runner.Error{Program: "/usr/bin/certbot", ExitCode: 1, Output: output, Err: errors.New("exit status 1")}
	}
}

// argument returns the value after flag in args.
func argument(args []string, flag string) string {
	index := slices.Index(args, flag)
	if index < 0 || index+1 >= len(args) {
		return ""
	}

	return args[index+1]
}

// writeCertificate stores a certificate for names as certbot would under
// the certificate name name.
func (e *testEnv) writeCertificate(name string, names ...string) {
	e.t.Helper()

	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		e.t.Fatal(err)
	}

	template := &x509.Certificate{
		SerialNumber: big.NewInt(1),
		Subject:      pkix.Name{CommonName: names[0]},
		DNSNames:     names,
		NotBefore:    testExpiry.AddDate(0, -3, 0),
		NotAfter:     testExpiry,
	}

	der, err := x509.CreateCertificate(rand.Reader, template, template, &key.PublicKey, key)
	if err != nil {
		e.t.Fatal(err)
	}

	keyDER, err := x509.MarshalECPrivateKey(key)
	if err != nil {
		e.t.Fatal(err)
	}

	live := filepath.Join(e.cfg.LetsEncryptDir, "live", name)
	e.write(filepath.Join(live, "fullchain.pem"), string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der})))
	e.write(filepath.Join(live, "privkey.pem"), string(pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: keyDER})))
	e.write(filepath.Join(e.cfg.LetsEncryptDir, "renewal", name+".conf"), "cert = "+live+"/cert.pem\n")
}

func (e *testEnv) issue(user, domain string) (Certificate, error) {
	return e.manager.IssueCertificate(context.Background(), user, domain)
}

func certonlyCall(names ...string) string {
	call := "certbot certonly --webroot -w $BASE/var/www/nusapanel-acme --cert-name " + names[0]
	for _, name := range names {
		call += " -d " + name
	}

	return call + " --non-interactive --agree-tos --keep-until-expiring --register-unsafely-without-email"
}

func deleteCall(domain string) string {
	return "certbot delete --cert-name " + domain + " --non-interactive"
}

// assertNoTokens checks that every reachability token was removed.
func (e *testEnv) assertNoTokens() {
	e.t.Helper()

	entries, err := os.ReadDir(filepath.Join(e.cfg.AcmeDir, ".well-known/acme-challenge"))
	if err != nil && !errors.Is(err, os.ErrNotExist) {
		e.t.Fatal(err)
	}

	if len(entries) != 0 {
		e.t.Fatalf("tokens left behind: %v", entries)
	}
}

func sslSite(forceHTTPS bool) Site {
	site := budiSite
	site.SSL = true
	site.ForceHTTPS = forceHTTPS

	return site
}

func TestRenderedNginxVhostWithHTTPS(t *testing.T) {
	env := newEnv(t)
	env.writeCertificate("example.com", "example.com", "www.example.com")
	env.mustApply(sslSite(false))

	nginx := env.read(env.nginxVhost("budi", "example.com"))
	live := env.cfg.LetsEncryptDir + "/live/example.com/"
	plain, https, found := strings.Cut(nginx, "\n}\n\nserver {\n")
	if !found {
		t.Fatalf("expected a second server:\n%s", nginx)
	}

	assertContains(t, "HTTPS server", https,
		"    listen 443 ssl http2;\n    server_name example.com www.example.com;\n",
		"ssl_certificate "+live+"fullchain.pem;\n",
		"ssl_certificate_key "+live+"privkey.pem;\n",
		"ssl_protocols TLSv1.2 TLSv1.3;\n",
		"access_log "+env.cfg.LogDir+"/example.com.access.log;",
		"error_log "+env.cfg.LogDir+"/example.com.error.log;",
		"server_tokens off;",
		"root /home/budi/domains/example.com/public_html;",
		"disable_symlinks if_not_owner from=$document_root;",
		"client_max_body_size 256m;",
		"include "+env.cfg.NginxDir+"/budi/suspended*.inc;",
		"location ~ /\\.ht {\n        deny all;\n    }",
		"try_files $uri @apache;",
		"location / {\n        proxy_pass http://127.0.0.1:8080;",
		"location @apache {\n        proxy_pass http://127.0.0.1:8080;",
		"proxy_set_header X-Forwarded-Proto $scheme;",
	)
	assertNotContains(t, "HTTPS server", https, "acme-challenge", "return 301", "listen 80", "[::]")

	// Without forcing HTTPS, port 80 still serves the website itself.
	assertContains(t, "HTTP server", plain, "    listen 80;\n", "location / {\n        proxy_pass", "root /home/budi/domains/example.com/public_html;")
	assertNotContains(t, "HTTP server", plain, "ssl", "443", "return 301")

	if strings.Count(nginx, "proxy_pass") != 4 || strings.Count(nginx, "{") != strings.Count(nginx, "}") {
		t.Fatalf("unexpected nginx vhost structure:\n%s", nginx)
	}

	env.write(env.cfg.IPv6File, "00000000000000000000000000000001 01 80 10 80       lo\n")
	env.mustApply(sslSite(false))

	assertContains(t, "nginx vhost with IPv6", env.read(env.nginxVhost("budi", "example.com")),
		"    listen 80;\n    listen [::]:80;\n    server_name",
		"    listen 443 ssl http2;\n    listen [::]:443 ssl http2;\n    server_name")
}

func TestRenderedNginxVhostForcingHTTPS(t *testing.T) {
	env := newEnv(t)
	env.writeCertificate("example.com", "example.com")
	env.mustApply(sslSite(true))

	nginx := env.read(env.nginxVhost("budi", "example.com"))
	plain, https, _ := strings.Cut(nginx, "\n}\n\nserver {\n")

	assertContains(t, "HTTP server", plain,
		"    listen 80;\n    server_name example.com www.example.com;\n",
		"location ^~ /.well-known/acme-challenge/ {\n        root "+env.cfg.AcmeDir+";\n        default_type text/plain;\n        try_files $uri =404;\n    }",
		"location / {\n        return 301 https://$host$request_uri;\n    }",
		"access_log "+env.cfg.LogDir+"/example.com.access.log;",
	)
	assertNotContains(t, "HTTP server", plain, "proxy_pass", "root /home", "suspended")

	assertContains(t, "HTTPS server", https, "listen 443 ssl http2;", "proxy_pass http://127.0.0.1:8080;")

	if strings.Count(nginx, "proxy_pass") != 2 {
		t.Fatalf("only the HTTPS server proxies:\n%s", nginx)
	}
}

func TestRenderedNginxVhostServesACMEChallenges(t *testing.T) {
	env := newEnv(t)
	env.mustApply(budiSite)

	nginx := env.read(env.nginxVhost("budi", "example.com"))

	assertContains(t, "nginx vhost", nginx,
		"location ^~ /.well-known/acme-challenge/ {\n        root "+env.cfg.AcmeDir+";\n        default_type text/plain;\n        try_files $uri =404;\n    }")

	if !env.manager.servesChallenges([]byte(nginx)) {
		t.Fatal("servesChallenges does not recognize the rendered vhost")
	}
}

func TestApplyDomainWithHTTPSNeedsACertificate(t *testing.T) {
	env := newEnv(t)
	// A key alone is not a certificate.
	env.write(filepath.Join(env.cfg.LetsEncryptDir, "live/example.com/privkey.pem"), "key")

	for _, site := range []Site{sslSite(false), sslSite(true)} {
		if _, err := env.apply(site); !errors.Is(err, ErrNoCertificate) {
			t.Fatalf("got %v, want ErrNoCertificate", err)
		}
	}

	env.assertCalls()
	env.assertMissing(env.nginxVhost("budi", "example.com"), env.poolFile("budi", "8.4"))

	if len(env.sites) != 0 {
		t.Fatalf("folders were prepared: %+v", env.sites)
	}
}

func TestManagerRefusesForcedHTTPSWithoutSSL(t *testing.T) {
	env := newEnv(t)
	env.writeCertificate("example.com", "example.com")

	site := budiSite
	site.ForceHTTPS = true

	if _, err := env.apply(site); err == nil || errors.Is(err, ErrNoCertificate) {
		t.Fatalf("got %v, want a validation error", err)
	}

	env.assertCalls()
}

func TestIssueCertificateCoversWWWWhenItReachesThisServer(t *testing.T) {
	env := newEnv(t)
	env.mustApply(budiSite)
	env.reach(nil, "example.com", "www.example.com")
	env.runner.reset()

	certificate, err := env.issue("budi", "example.com")
	if err != nil {
		t.Fatalf("issue failed: %v", err)
	}

	if !slices.Equal(certificate.Names, []string{"example.com", "www.example.com"}) || !certificate.ExpiresAt.Equal(testExpiry) ||
		certificate.ExpiresAt.Location() != time.UTC {
		t.Fatalf("unexpected certificate %+v", certificate)
	}

	// The vhost does not use the certificate yet, so nothing is reloaded.
	env.assertCalls(certonlyCall("example.com", "www.example.com"))
	env.assertNoTokens()

	info, err := os.Stat(filepath.Join(env.cfg.AcmeDir, ".well-known/acme-challenge"))
	if err != nil || info.Mode().Perm() != 0o755 {
		t.Fatalf("challenge folder: %v %v, want mode 0755", info, err)
	}
}

func TestIssueCertificateLeavesOutAWWWThatDoesNotReachThisServer(t *testing.T) {
	other := http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write([]byte("parked domain"))
	})

	for description, setUp := range map[string]func(env *testEnv){
		"no DNS record": func(*testEnv) {},
		"another site":  func(env *testEnv) { env.reach(other, "www.example.com") },
	} {
		env := newEnv(t)
		env.mustApply(budiSite)
		// The certificate had www before; certbot replaces its names.
		env.writeCertificate("example.com", "example.com", "www.example.com")
		env.reach(nil, "example.com")
		setUp(env)
		env.runner.reset()

		certificate, err := env.issue("budi", "example.com")
		if err != nil {
			t.Fatalf("%s: issue failed: %v", description, err)
		}

		if !slices.Equal(certificate.Names, []string{"example.com"}) {
			t.Fatalf("%s: names = %v", description, certificate.Names)
		}

		env.assertCalls(certonlyCall("example.com"))
		env.assertNoTokens()
		assertContains(t, "agent log", env.logs.String(), "certificate requested without www")
	}
}

func TestIssueCertificateNeedsTheDomainToReachThisServer(t *testing.T) {
	tests := map[string]struct {
		setUp  func(env *testEnv)
		reason string
	}{
		"no DNS record": {func(*testEnv) {}, "no DNS record"},
		"another site": {func(env *testEnv) {
			env.reach(http.NotFoundHandler(), "example.com")
		}, "it answers with another site"},
		"another answer": {func(env *testEnv) {
			env.reach(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
				_, _ = w.Write([]byte("welcome"))
			}), "example.com")
		}, "it answers with another site"},
		"timed out": {func(env *testEnv) {
			env.hosts["example.com"] = os.ErrDeadlineExceeded
		}, "timed out"},
		"redirect to another port": {func(env *testEnv) {
			env.reach(http.RedirectHandler("http://example.com:8080/", http.StatusFound), "example.com")
		}, "it redirects somewhere Let's Encrypt does not follow"},
	}

	for description, test := range tests {
		env := newEnv(t)
		env.mustApply(budiSite)
		env.reach(nil, "www.example.com")
		test.setUp(env)
		env.runner.reset()

		_, err := env.issue("budi", "example.com")

		unreachable, ok := errors.AsType[*UnreachableError](err)
		if !ok || unreachable.Domain != "example.com" || unreachable.Reason != test.reason {
			t.Fatalf("%s: got %v, want reason %q", description, err, test.reason)
		}

		env.assertCalls()
		env.assertNoTokens()
	}
}

func TestIssueCertificateFollowsRedirectsLikeLetsEncrypt(t *testing.T) {
	env := newEnv(t)
	env.mustApply(budiSite)
	env.reach(nil, "www.example.com")
	env.reach(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, "http://www.example.com"+r.URL.Path, http.StatusMovedPermanently)
	}), "example.com")

	if _, err := env.issue("budi", "example.com"); err != nil {
		t.Fatalf("a redirect to the same token was not followed: %v", err)
	}

	// An endless redirect stops.
	env.reach(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, r.URL.Path+"x", http.StatusFound)
	}), "example.com")

	_, err := env.issue("budi", "example.com")
	if unreachable, ok := errors.AsType[*UnreachableError](err); !ok || !strings.Contains(unreachable.Reason, "redirects") {
		t.Fatalf("got %v, want a refused redirect", err)
	}
}

func TestIssueCertificateNeedsAUsableVhostOfTheAccount(t *testing.T) {
	env := newEnv(t)

	if _, err := env.issue("budi", "example.com"); !errors.Is(err, ErrDomainNotSetUp) {
		t.Fatalf("no vhost: got %v, want ErrDomainNotSetUp", err)
	}

	// Another account's vhost does not count.
	env.mustApply(Site{User: "sari", UID: 1002, GID: 1002, Domain: "example.com", PHPVersion: "8.4"})

	if _, err := env.issue("budi", "example.com"); !errors.Is(err, ErrDomainNotSetUp) {
		t.Fatalf("vhost of sari: got %v, want ErrDomainNotSetUp", err)
	}

	// A vhost from before vhosts served Let's Encrypt challenges.
	env.write(env.nginxVhost("budi", "budi.id"), "server {\n    listen 80;\n    server_name budi.id www.budi.id;\n}\n")

	if _, err := env.issue("budi", "budi.id"); !errors.Is(err, ErrVhostOutdated) {
		t.Fatalf("old vhost: got %v, want ErrVhostOutdated", err)
	}

	env.mustApply(budiSite)

	if err := env.manager.SuspendAccount(context.Background(), "budi"); err != nil {
		t.Fatal(err)
	}

	env.runner.reset()

	if _, err := env.issue("budi", "example.com"); !errors.Is(err, ErrAccountSuspended) {
		t.Fatalf("suspended: got %v, want ErrAccountSuspended", err)
	}

	env.assertCalls()

	for _, domain := range []string{"../etc", "example.com;"} {
		if _, err := env.issue("budi", domain); err == nil {
			t.Fatalf("issue accepted %q", domain)
		}
	}
}

func TestIssueCertificateReloadsAVhostAlreadyServingHTTPS(t *testing.T) {
	env := newEnv(t)
	env.writeCertificate("example.com", "example.com", "www.example.com")
	env.mustApply(sslSite(true))
	env.reach(nil, "example.com", "www.example.com")
	env.runner.reset()

	if _, err := env.issue("budi", "example.com"); err != nil {
		t.Fatalf("issue failed: %v", err)
	}

	env.assertCalls(certonlyCall("example.com", "www.example.com"), "nginx -t", "systemctl reload nginx")
}

func TestIssueCertificateReportsCertbotFailures(t *testing.T) {
	env := newEnv(t)
	env.mustApply(budiSite)
	env.reach(nil, "example.com")
	env.runner.certbot = certbotFailing(certbotChallengeFailure)

	_, err := env.issue("budi", "example.com")

	issue, ok := errors.AsType[*IssueError](err)
	if !ok || issue.Domain != "example.com" || issue.RateLimited ||
		issue.Detail != "203.0.113.10: Invalid response from http://example.com/.well-known/acme-challenge/abc: 404" {
		t.Fatalf("got %#v", err)
	}

	assertContains(t, "agent log", env.logs.String(), "certbot could not issue a certificate", "Some challenges have failed")
}

const certbotChallengeFailure = `Saving debug log to /var/log/letsencrypt/letsencrypt.log
Requesting a certificate for example.com

Certbot failed to authenticate some domains (authenticator: webroot). The Certificate Authority reported these problems:
  Domain: example.com
  Type:   unauthorized
  Detail: 203.0.113.10: Invalid response from http://example.com/.well-known/acme-challenge/abc: 404

Hint: The Certificate Authority failed to download the temporary challenge files created by Certbot.

Some challenges have failed.
Ask for help or search for solutions at https://community.letsencrypt.org. See the logfile /var/log/letsencrypt/letsencrypt.log or re-run Certbot with -v for more details.
`

func TestCertbotIssueFailures(t *testing.T) {
	tests := map[string]struct {
		output string
		want   error
	}{
		"challenge": {certbotChallengeFailure, &IssueError{
			Domain: "example.com",
			Detail: "203.0.113.10: Invalid response from http://example.com/.well-known/acme-challenge/abc: 404",
		}},
		"rate limit": {"Requesting a certificate for example.com\nAn unexpected error occurred:\n" +
			"urn:ietf:params:acme:error:rateLimited :: There were too many requests of a given type :: " +
			"Error creating new order :: too many certificates (5) already issued for this exact set of identifiers " +
			"in the last 168h0m0s, retry after 2026-11-09 05:28:43 UTC: see https://letsencrypt.org/docs/rate-limits/#new-certificates-per-exact-set-of-hostnames\n",
			&IssueError{
				Domain:      "example.com",
				RateLimited: true,
				Detail:      "too many certificates (5) already issued for this exact set of identifiers in the last 168h0m0s, retry after 2026-11-09 05:28:43 UTC",
			}},
		"rate limit without its type": {"Error creating new order :: too many failed authorizations recently: see https://letsencrypt.org/docs/rate-limits/\n",
			&IssueError{Domain: "example.com", RateLimited: true}},
		"other ACME error": {"An unexpected error occurred:\nurn:ietf:params:acme:error:rejectedIdentifier :: " +
			"The server will not issue certificates for the identifier :: Error creating new order :: Cannot issue for \"example.com\": The ACME server refuses to issue a certificate for this domain name, because it is forbidden by policy\n",
			&IssueError{Domain: "example.com", Detail: "Cannot issue for \"example.com\": The ACME server refuses to issue a certificate for this domain name, because it is forbidden by policy"}},
		"unknown": {"Traceback (most recent call last):\n  File \"/usr/lib/python3/dist-packages/certbot/main.py\"\n", &IssueError{Domain: "example.com"}},
	}

	for description, test := range tests {
		err := certbotIssueFailure("example.com", test.output)

		issue, ok := errors.AsType[*IssueError](err)
		if !ok || *issue != *test.want.(*IssueError) {
			t.Errorf("%s: got %#v, want %#v", description, err, test.want)
		}
	}

	busy := certbotIssueFailure("example.com", "Another instance of Certbot is already running.\n")
	if !errors.Is(busy, ErrCertbotBusy) {
		t.Fatalf("lock: got %v, want ErrCertbotBusy", busy)
	}

	long := certbotIssueFailure("example.com", "  Detail: "+strings.Repeat("x", 1000)+"\n")
	if issue, _ := errors.AsType[*IssueError](long); len(issue.Detail) != maxDetailLength+3 {
		t.Fatalf("detail not shortened: %d characters", len(issue.Detail))
	}
}

func TestCertbotIssueArgsNeverExpand(t *testing.T) {
	args := certbotIssueArgs("/var/www/nusapanel-acme", "example.com", []string{"example.com", "www.example.com"})

	want := []string{
		"certonly", "--webroot", "-w", "/var/www/nusapanel-acme", "--cert-name", "example.com",
		"-d", "example.com", "-d", "www.example.com",
		"--non-interactive", "--agree-tos", "--keep-until-expiring", "--register-unsafely-without-email",
	}
	if !slices.Equal(args, want) {
		t.Fatalf("args = %q", args)
	}
}

func TestDeleteCertificate(t *testing.T) {
	env := newEnv(t)
	env.writeCertificate("example.com", "example.com", "www.example.com")
	env.mustApply(sslSite(false))
	env.runner.reset()

	if err := env.manager.DeleteCertificate(context.Background(), "budi", "example.com"); !errors.Is(err, ErrCertificateInUse) {
		t.Fatalf("got %v, want ErrCertificateInUse", err)
	}

	env.assertCalls()

	env.mustApply(budiSite)
	env.runner.reset()

	if err := env.manager.DeleteCertificate(context.Background(), "budi", "example.com"); err != nil {
		t.Fatalf("delete failed: %v", err)
	}

	env.assertCalls(deleteCall("example.com"))
	env.assertMissing(filepath.Join(env.cfg.LetsEncryptDir, "renewal/example.com.conf"))

	// No certificate left: nothing to do.
	env.runner.reset()

	if err := env.manager.DeleteCertificate(context.Background(), "budi", "example.com"); err != nil {
		t.Fatalf("second delete failed: %v", err)
	}

	env.assertCalls()

	if err := env.manager.DeleteCertificate(context.Background(), "budi", "budi.id"); !errors.Is(err, ErrDomainNotSetUp) {
		t.Fatalf("got %v, want ErrDomainNotSetUp", err)
	}
}

func TestDeleteCertificateReportsABusyCertbot(t *testing.T) {
	env := newEnv(t)
	env.mustApply(budiSite)
	env.writeCertificate("example.com", "example.com")
	env.runner.certbot = certbotFailing("Another instance of Certbot is already running.\n")

	if err := env.manager.DeleteCertificate(context.Background(), "budi", "example.com"); !errors.Is(err, ErrCertbotBusy) {
		t.Fatalf("got %v, want ErrCertbotBusy", err)
	}
}

func TestRemoveDomainDeletesItsCertificate(t *testing.T) {
	env := newEnv(t)
	env.writeCertificate("example.com", "example.com", "www.example.com")
	env.writeCertificate("budi.id", "budi.id")
	env.mustApply(sslSite(true))
	env.mustApply(Site{User: "budi", UID: 1001, GID: 1001, Domain: "budi.id", PHPVersion: "8.4", SSL: true})
	env.runner.reset()

	if err := env.manager.RemoveDomain(context.Background(), "budi", "example.com"); err != nil {
		t.Fatalf("remove failed: %v", err)
	}

	env.assertCalls(append(slices.Clone(reloadBoth), deleteCall("example.com"))...)

	if !env.manager.HasCertificate("budi.id") || env.manager.HasCertificate("example.com") {
		t.Fatal("the wrong certificates were deleted")
	}

	// Repeating it does not run certbot again.
	env.runner.reset()

	if err := env.manager.RemoveDomain(context.Background(), "budi", "example.com"); err != nil {
		t.Fatalf("second remove failed: %v", err)
	}

	env.assertCalls(reloadBoth...)
}

func TestRemoveDomainKeepsTheCertificateOfAnotherAccountsWebsite(t *testing.T) {
	env := newEnv(t)
	env.writeCertificate("example.com", "example.com")
	env.mustApply(Site{User: "sari", UID: 1002, GID: 1002, Domain: "example.com", PHPVersion: "8.4", SSL: true})
	env.runner.reset()

	if err := env.manager.RemoveDomain(context.Background(), "budi", "example.com"); err != nil {
		t.Fatalf("remove failed: %v", err)
	}

	env.assertCalls(reloadBoth...)

	if !env.manager.HasCertificate("example.com") {
		t.Fatal("the certificate of sari's website was deleted")
	}
}

func TestRemoveAccountDeletesItsCertificatesAlsoOnARetry(t *testing.T) {
	env := newEnv(t)
	env.writeCertificate("example.com", "example.com", "www.example.com")
	env.writeCertificate("budi.id", "budi.id")
	env.writeCertificate("sari.id", "sari.id")
	env.mustApply(sslSite(true))
	env.mustApply(Site{User: "budi", UID: 1001, GID: 1001, Domain: "budi.id", PHPVersion: "8.4"})
	env.mustApply(Site{User: "budi", UID: 1001, GID: 1001, Domain: "budi.net", PHPVersion: "8.4"})
	env.mustApply(Site{User: "sari", UID: 1002, GID: 1002, Domain: "sari.id", PHPVersion: "8.4", SSL: true})

	// certbot.timer holds certbot's lock during the first attempt.
	env.runner.certbot = certbotFailing("Another instance of Certbot is already running.\n")

	if err := env.manager.RemoveAccount(context.Background(), "budi"); !errors.Is(err, ErrCertbotBusy) {
		t.Fatalf("first attempt: got %v, want ErrCertbotBusy", err)
	}

	env.assertMissing(env.nginxVhost("budi", "example.com"), env.nginxVhost("budi", "budi.id"))

	env.runner.certbot = env.fakeCertbot
	env.runner.reset()

	if err := env.manager.RemoveAccount(context.Background(), "budi"); err != nil {
		t.Fatalf("retry failed: %v", err)
	}

	env.assertCalls(append(append([]string{"systemctl disable --now nusa-php84@budi.service"}, reloadBoth...),
		deleteCall("budi.id"), deleteCall("example.com"))...)
	env.assertMissing(filepath.Join(env.cfg.NginxDir, "budi"), filepath.Join(env.cfg.ApacheDir, "budi"))

	if env.manager.HasCertificate("example.com") || env.manager.HasCertificate("budi.id") || !env.manager.HasCertificate("sari.id") {
		t.Fatal("the wrong certificates were deleted")
	}
}

func TestRemoveAccountKeepsTheDomainListWhenRejected(t *testing.T) {
	env := newEnv(t)
	env.mustApply(budiSite)
	env.runner.fail = []string{"nginx -t"}

	if err := env.manager.RemoveAccount(context.Background(), "budi"); !errors.Is(err, ErrConfigRejected) {
		t.Fatalf("got %v, want ErrConfigRejected", err)
	}

	// The rollback takes the list back with the vhosts.
	env.read(env.nginxVhost("budi", "example.com"))
	env.assertMissing(env.manager.removedDomainsFile("budi"))
}

func TestReachabilityCheckOnlyDialsPublicAddresses(t *testing.T) {
	for address, public := range map[string]bool{
		"203.0.113.10": true, "93.184.216.34": true, "2606:2800:220:1::1": true,
		"127.0.0.1": false, "10.1.2.3": false, "172.16.0.1": false, "192.168.1.1": false, "169.254.169.254": false,
		"100.64.0.1": false, "0.0.0.0": false, "0.1.2.3": false, "224.0.0.1": false, "255.255.255.255": false,
		"::1": false, "fe80::1": false, "fd00::1": false, "::ffff:127.0.0.1": false, "::": false,
	} {
		if got := isPublicAddress(netip.MustParseAddr(address)); got != public {
			t.Errorf("isPublicAddress(%s) = %v, want %v", address, got, public)
		}
	}

	if err := refuseNonPublic("tcp4", "127.0.0.1:80", nil); !errors.As(err, new(*nonPublicAddressError)) {
		t.Fatalf("got %v, want a refused address", err)
	}

	if err := refuseNonPublic("tcp6", "[2606:2800:220:1::1]:80", nil); err != nil {
		t.Fatalf("a public address was refused: %v", err)
	}

	// The real dialer refuses a name that resolves to loopback.
	env := newEnv(t)
	env.cfg.DialHTTP = nil
	manager := New(env.cfg)

	err := manager.checkReaches(context.Background(), "localhost")
	if unreachable, ok := errors.AsType[*UnreachableError](err); !ok || unreachable.Reason != "its DNS record points to a private address" {
		t.Fatalf("got %v, want a private address", err)
	}

	env.assertNoTokens()
}

func TestDefaultConfigCertificatePaths(t *testing.T) {
	manager := New(DefaultConfig())

	if manager.certificateFile("example.com") != "/etc/letsencrypt/live/example.com/fullchain.pem" ||
		manager.certificateKeyFile("example.com") != "/etc/letsencrypt/live/example.com/privkey.pem" ||
		manager.renewalFile("example.com") != "/etc/letsencrypt/renewal/example.com.conf" ||
		manager.challengeDir() != "/var/www/nusapanel-acme/.well-known/acme-challenge" {
		t.Fatal("unexpected certificate paths")
	}

	if manager.cfg.DialHTTP == nil {
		t.Fatal("the reachability check has no dialer")
	}
}
