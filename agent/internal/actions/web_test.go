package actions

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"os"
	"path/filepath"
	"runtime"
	"slices"
	"strings"
	"sync"
	"testing"

	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/runner"
	"rayxo/agent/internal/sysuser"
	"rayxo/agent/internal/webserver"
)

var webMethodNames = []string{"web.apply_domain", "web.remove_domain", "php.remove_pool", "php.versions", "ssl.issue", "ssl.install", "ssl.delete", "ssl.expiry"}

// webEnv runs the web methods against a fake account system and a real
// webserver.Manager whose files live in a temporary directory and whose
// programs are faked.
type webEnv struct {
	t      *testing.T
	system *fakeSystem
	cfg    webserver.Config
	web    *webserver.Manager

	mu       sync.Mutex
	programs []string
	sites    []sysuser.Site
	// failing lists program names (e.g. "nginx") that exit with code 1.
	failing []string
	// certbot runs instead of every certbot call.
	certbot func(args []string) ([]byte, error)
	// reachable maps the names that reach this server's ACME webroot to
	// its address; other names have no DNS record.
	reachable map[string]string
	// nameservers are what public DNS gives as the nameservers of a
	// domain.
	nameservers map[string][]string
}

func newWebEnv(t *testing.T) *webEnv {
	t.Helper()

	base := t.TempDir()
	env := &webEnv{t: t, system: newFakeSystem(), reachable: map[string]string{}, nameservers: map[string][]string{}}
	env.certbot = env.fakeCertbot
	env.system.addAccount("budi", 1001)
	env.system.addAccount("sari", 1002)

	env.cfg = webserver.Config{
		NginxDir:             filepath.Join(base, "nginx"),
		ApacheDir:            filepath.Join(base, "apache2"),
		PoolDir:              filepath.Join(base, "php-fpm"),
		UnitDir:              filepath.Join(base, "systemd"),
		LogDir:               filepath.Join(base, "log"),
		RunDir:               "/run/rayxo-php",
		HomeRoot:             "/home",
		PHPFPMDir:            filepath.Join(base, "sbin"),
		IPv6File:             filepath.Join(base, "if_inet6"),
		SitesDir:             filepath.Join(base, "sites-enabled"),
		PanelSnippet:         filepath.Join(base, "snippets/rayxo-panel.conf"),
		Backend:              "127.0.0.1:8080",
		AcmeDir:              filepath.Join(base, "acme"),
		LetsEncryptDir:       filepath.Join(base, "letsencrypt"),
		CustomCertificateDir: filepath.Join(base, "ssl"),
		Hostnames:            func() []string { return []string{"server1.example.net"} },
		Run:                  env.run,
		DialHTTP:             env.dial,
		AcmeDNSHook:          "/usr/local/rayxo/bin/rayxo acme-dns-hook",
		LookupNS: func(_ context.Context, domain string) ([]string, error) {
			env.mu.Lock()
			defer env.mu.Unlock()

			return env.nameservers[domain], nil
		},
		PrepareSite: func(_ context.Context, site sysuser.Site) error {
			env.mu.Lock()
			defer env.mu.Unlock()

			env.sites = append(env.sites, site)

			return nil
		},
		Logger: slog.New(slog.NewTextHandler(io.Discard, nil)),
	}

	env.write(filepath.Join(env.cfg.PHPFPMDir, "php-fpm8.4"))
	env.web = webserver.New(env.cfg)

	return env
}

func (e *webEnv) run(_ context.Context, program string, args ...string) ([]byte, error) {
	e.mu.Lock()
	defer e.mu.Unlock()

	name := filepath.Base(program)
	e.programs = append(e.programs, name+" "+args[0])

	if slices.Contains(e.failing, name) {
		return nil, &runner.Error{Program: program, ExitCode: 1, Output: "emerg: unexpected }", Err: errors.New("exit status 1")}
	}

	if name == "certbot" {
		return e.certbot(args)
	}

	if name == "systemctl" && args[0] == "is-active" {
		return nil, &runner.Error{Program: program, ExitCode: 3, Err: errors.New("exit status 3")}
	}

	return nil, nil
}

func (e *webEnv) write(path string) {
	e.t.Helper()

	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		e.t.Fatal(err)
	}

	if err := os.WriteFile(path, nil, 0o644); err != nil {
		e.t.Fatal(err)
	}
}

func (e *webEnv) call(method string, params any) (json.RawMessage, error) {
	e.t.Helper()

	raw, err := json.Marshal(params)
	if err != nil {
		e.t.Fatal(err)
	}

	registered, ok := webMethods(e.system, e.web)[method]
	if !ok {
		e.t.Fatalf("unknown method %s", method)
	}

	if registered.LockKey != nil {
		if _, err := registered.LockKey(raw); err != nil {
			return nil, err
		}
	}

	result, err := registered.Handler(context.Background(), raw)
	if err != nil {
		return nil, err
	}

	encoded, err := json.Marshal(result)
	if err != nil {
		e.t.Fatalf("result cannot be encoded: %v", err)
	}

	return encoded, nil
}

// assertUntouched checks that nothing beyond looking up the account
// happened.
func (e *webEnv) assertUntouched(lookups ...string) {
	e.t.Helper()

	assertCalls(e.t, e.system, lookups...)

	if len(e.programs) != 0 || len(e.sites) != 0 {
		e.t.Fatalf("the system was changed: programs %v, sites %v", e.programs, e.sites)
	}

	if _, err := os.Stat(e.cfg.NginxDir + "/budi"); !errors.Is(err, os.ErrNotExist) {
		e.t.Fatalf("an Nginx config directory was created (%v)", err)
	}
}

func applyParams(domain, version string) map[string]string {
	return map[string]string{"username": "budi", "domain": domain, "php_version": version}
}

func TestWebMethodsAreRegistered(t *testing.T) {
	registry := rpc.NewRegistry()
	Register(registry, Config{})

	for _, name := range webMethodNames {
		if !slices.Contains(registry.Names(), name) {
			t.Errorf("method %s is not registered", name)
		}
	}
}

func TestWebMethodsLockTheAccount(t *testing.T) {
	params := map[string]string{
		"web.apply_domain":  `{"username":"budi","domain":"example.com","php_version":"8.4"}`,
		"web.remove_domain": `{"username":"budi","domain":"example.com"}`,
		"php.remove_pool":   `{"username":"budi","php_version":"8.4"}`,
		"ssl.issue":         `{"username":"budi","domain":"example.com"}`,
		"ssl.delete":        `{"username":"budi","domain":"example.com"}`,
		"ssl.install":       `{"username":"budi","domain":"example.com","certificate":"x","private_key":"y"}`,
	}

	env := newWebEnv(t)

	for name, method := range webMethods(env.system, env.web) {
		if name == "php.versions" || name == "ssl.expiry" {
			// Only reads, so it locks nothing (checked in its own test).
			continue
		}

		key, err := method.LockKey(json.RawMessage(params[name]))
		if err != nil || key != "account:budi" {
			t.Fatalf("%s lock key = %q, %v; want account:budi", name, key, err)
		}

		_, err = method.LockKey(json.RawMessage(strings.Replace(params[name], "budi", "root", 1)))
		assertCode(t, err, rpc.CodeInvalidParams)
	}
}

func TestWebMethodsRejectInvalidParams(t *testing.T) {
	invalid := map[string][]map[string]any{
		"web.apply_domain": {
			{"username": "root", "domain": "example.com", "php_version": "8.4"},
			{"username": "budi", "domain": "example.com;", "php_version": "8.4"},
			{"username": "budi", "domain": "Example.com", "php_version": "8.4"},
			{"username": "budi", "domain": "example.com\nlisten 81", "php_version": "8.4"},
			{"username": "budi", "domain": "../etc", "php_version": "8.4"},
			{"username": "budi", "domain": "example.com", "php_version": "8"},
			{"username": "budi", "domain": "example.com", "php_version": "8.4.1"},
			{"username": "budi", "domain": "example.com", "php_version": "84"},
			{"username": "budi", "domain": "example.com", "php_version": "../8.4"},
			{"username": "budi", "domain": "example.com", "php_version": 8.4},
			{"username": "budi", "domain": "example.com"},
			{"username": "budi", "php_version": "8.4"},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "root": "/etc"},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "force_https": true},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "ssl": false, "force_https": true},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "ssl": "yes"},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "ssl": 1},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "kind": "proxy"},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "php_settings": map[string]string{"open_basedir": "/"}},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "php_settings": map[string]string{"memory_limit": "1G"}},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "php_settings": map[string]int{"max_input_vars": 5000}},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "kind": "alias"},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "kind": "alias", "parent": "example.com"},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "kind": "forward", "parent": "../etc"},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "parent": "example.org"},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "redirects": []map[string]any{{"path": "/a b", "destination": "https://example.org/", "status": 301}}},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "redirects": []map[string]any{{"path": "/a", "destination": "https://example.org/$host", "status": 301}}},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "redirects": []map[string]any{{"path": "/a", "destination": "https://example.org/", "status": 200}}},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "redirects": []map[string]any{{"path": "/a", "destination": "https://example.org/", "status": 301, "regex": true}}},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "error_pages": []int{418}},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "error_pages": []string{"404"}},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "protected_directories": []map[string]any{{"path": "/a", "realm": "x", "users": []map[string]any{{"name": "a", "hash": "plain"}}}}},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "protected_directories": []map[string]any{{"path": "/../etc", "realm": "x"}}},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "protected_directories": []map[string]any{{"path": "/a", "realm": "x\"; allow all; #"}}},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "hotlink": map[string]any{"extensions": []string{"php"}}},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "hotlink": map[string]any{"extensions": []string{"jpg"}, "redirect_to": "https://example.com/x.png"}},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "mime_types": []map[string]any{{"extension": "foo", "type": "text/plain; x"}}},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "php_extensions": []string{"js"}},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "ipv4": "2001:db8::10"},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "ipv4": "192.0.2.10:8080"},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "ipv6": "192.0.2.10"},
			{"username": "budi", "domain": "example.com", "php_version": "8.4", "ipv6": "[2001:db8::10]"},
		},
		"web.remove_domain": {
			{"username": "budi"},
			{"username": "budi", "domain": "a..b"},
			{"username": "1budi", "domain": "example.com"},
			{"username": "budi", "domain": "example.com", "php_version": "8.4"},
		},
		"php.remove_pool": {
			{"username": "budi"},
			{"username": "budi", "php_version": "8.x"},
			{"username": "mysql", "php_version": "8.4"},
			{"username": "budi", "php_version": "8.4", "domain": "example.com"},
		},
		"ssl.issue": {
			{"username": "budi"},
			{"username": "budi", "domain": "example.com;"},
			{"username": "root", "domain": "example.com"},
			{"username": "budi", "domain": "example.com", "email": "a@b.c"},
		},
		"ssl.expiry": {
			{},
			{"domains": []string{}},
			{"domains": []string{"../etc"}},
			{"domains": []string{"example.com", "example.com"}},
			{"domains": manyDomains(501)},
			{"domains": []string{"example.com"}, "username": "budi"},
		},
		"ssl.delete": {
			{"domain": "example.com"},
			{"username": "budi", "domain": "../etc"},
			{"username": "budi", "domain": "example.com", "php_version": "8.4"},
		},
	}

	for method, cases := range invalid {
		for _, params := range cases {
			env := newWebEnv(t)

			_, err := env.call(method, params)

			assertCode(t, err, rpc.CodeInvalidParams)
			env.assertUntouched()
		}
	}
}

func TestApplyDomainSetsUpTheWebsite(t *testing.T) {
	env := newWebEnv(t)

	result, err := env.call("web.apply_domain", applyParams("example.com", "8.4"))
	if err != nil {
		t.Fatalf("apply failed: %v", err)
	}

	if string(result) != `{"domain":"example.com","document_root":"/home/budi/domains/example.com/public_html"}` {
		t.Fatalf("unexpected result %s", result)
	}

	if len(env.sites) != 1 || env.sites[0].Home != "/home/budi" || env.sites[0].UID != 1001 || env.sites[0].GID != 1001 {
		t.Fatalf("folders prepared as %+v", env.sites)
	}

	for _, path := range []string{
		env.cfg.NginxDir + "/budi/example.com.conf",
		env.cfg.ApacheDir + "/budi/example.com.conf",
		env.cfg.PoolDir + "/8.4/budi.conf",
		env.cfg.UnitDir + "/rayxo-php84@.service",
	} {
		if _, err := os.Stat(path); err != nil {
			t.Errorf("%s was not written: %v", path, err)
		}
	}

	// The same call again gives the same result.
	again, err := env.call("web.apply_domain", applyParams("example.com", "8.4"))
	if err != nil || string(again) != string(result) {
		t.Fatalf("second apply: %s, %v", again, err)
	}
}

func TestApplyDomainServesTheWebsiteOnTheAccountsAddresses(t *testing.T) {
	env := newWebEnv(t)

	params := applyParams("example.com", "8.4")
	params["ipv4"] = "192.0.2.10"
	params["ipv6"] = "2001:db8::10"

	if _, err := env.call("web.apply_domain", params); err != nil {
		t.Fatalf("apply failed: %v", err)
	}

	vhost, err := os.ReadFile(env.cfg.NginxDir + "/budi/example.com.conf")
	if err != nil {
		t.Fatal(err)
	}

	for _, listen := range []string{"listen 192.0.2.10:80;", "listen [2001:db8::10]:80;"} {
		if !strings.Contains(string(vhost), listen) {
			t.Errorf("the vhost has no %q:\n%s", listen, vhost)
		}
	}
}

func TestApplyDomainSetsUpPointersAndRedirects(t *testing.T) {
	env := newWebEnv(t)

	params := map[string]any{
		"username":    "budi",
		"domain":      "example.net",
		"php_version": "8.4",
		"kind":        "alias",
		"parent":      "example.com",
		"redirects": []map[string]any{
			{"path": "/promo", "destination": "https://example.com/sale", "status": 302, "wildcard": true},
		},
	}

	result, err := env.call("web.apply_domain", params)
	if err != nil {
		t.Fatalf("apply failed: %v", err)
	}

	if string(result) != `{"domain":"example.net","document_root":"/home/budi/domains/example.com/public_html"}` {
		t.Fatalf("unexpected result %s", result)
	}

	nginx, err := os.ReadFile(env.cfg.NginxDir + "/budi/example.net.conf")
	if err != nil || !strings.Contains(string(nginx), `return 302 "https://example.com/sale/$rayxo_rest$is_args$args";`) {
		t.Fatalf("the redirect is missing from the vhost (%v):\n%s", err, nginx)
	}

	params["kind"] = "forward"
	delete(params, "redirects")

	result, err = env.call("web.apply_domain", params)
	if err != nil || string(result) != `{"domain":"example.net","document_root":""}` {
		t.Fatalf("forward: %s, %v", result, err)
	}

	if _, err := os.Stat(env.cfg.ApacheDir + "/budi/example.net.conf"); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("a forward kept its Apache vhost (%v)", err)
	}
}

func TestApplyDomainSetsUpTheWebSettings(t *testing.T) {
	env := newWebEnv(t)

	hash := "$6$abcdefgh12345678$H.n1EWQmWoEgsATLJ.dVjWe9Vj1ZCEW73beozLP7CEywnTxqwob8sTUvv52IPEcyMI/6uWNNTntLRkDaTMPKS/"
	params := map[string]any{
		"username":    "budi",
		"domain":      "example.com",
		"php_version": "8.4",
		"error_pages": []int{404},
		"protected_directories": []map[string]any{
			{"path": "/admin", "realm": "Admin area", "users": []map[string]any{{"name": "budi", "hash": hash}}},
		},
		"hotlink":        map[string]any{"extensions": []string{"jpg"}, "domains": []string{"example.org"}, "allow_direct": true, "redirect_to": "https://example.net/no.png"},
		"mime_types":     []map[string]any{{"extension": "foo", "type": "application/x-foo"}},
		"php_extensions": []string{"html"},
		"cgi":            true,
	}

	if _, err := env.call("web.apply_domain", params); err != nil {
		t.Fatalf("apply failed: %v", err)
	}

	nginx, err := os.ReadFile(env.cfg.NginxDir + "/budi/example.com.conf")
	if err != nil {
		t.Fatal(err)
	}

	for _, want := range []string{
		"error_page 404 /.errors/404.html;",
		"location ~ ^/admin(?:/|$) {\n        auth_basic \"Admin area\";",
		"valid_referers none blocked server_names *.example.com example.org *.example.org;",
		`return 302 "https://example.net/no.png";`,
	} {
		if !strings.Contains(string(nginx), want) {
			t.Errorf("the nginx vhost lacks %q:\n%s", want, nginx)
		}
	}

	apache, err := os.ReadFile(env.cfg.ApacheDir + "/budi/example.com.conf")
	if err != nil {
		t.Fatal(err)
	}

	for _, want := range []string{"ErrorDocument 404 /.errors/404.html", "AddType application/x-foo .foo", `<FilesMatch "\.(php|phtml|html)$">`, `<Directory "/home/budi/domains/example.com/public_html/admin">`, "SuexecUserGroup budi budi"} {
		if !strings.Contains(string(apache), want) {
			t.Errorf("the apache vhost lacks %q:\n%s", want, apache)
		}
	}

	files, err := filepath.Glob(env.cfg.NginxDir + "/budi/example.com@*.htpasswd")
	if err != nil || len(files) != 1 {
		t.Fatalf("user files %v (%v)", files, err)
	}

	if content, err := os.ReadFile(files[0]); err != nil || string(content) != "budi:"+hash+"\n" {
		t.Fatalf("user file %q (%v)", content, err)
	}
}

func TestPHPVersionsListsTheInstalledVersions(t *testing.T) {
	env := newWebEnv(t)
	env.write(filepath.Join(env.cfg.PHPFPMDir, "php-fpm7.4"))
	env.write(filepath.Join(env.cfg.PHPFPMDir, "php-fpm8.5"))

	result, err := env.call("php.versions", map[string]any{})
	if err != nil {
		t.Fatalf("php.versions failed: %v", err)
	}

	if string(result) != `{"versions":["7.4","8.4","8.5"]}` {
		t.Fatalf("unexpected result %s", result)
	}

	// It takes no params and changes nothing.
	_, err = env.call("php.versions", map[string]any{"username": "budi"})
	assertCode(t, err, rpc.CodeInvalidParams)
	env.assertUntouched()

	if webMethods(env.system, env.web)["php.versions"].LockKey != nil {
		t.Fatal("php.versions must not lock an account")
	}
}

func TestApplyDomainNeedsAnInstalledPHP(t *testing.T) {
	env := newWebEnv(t)

	_, err := env.call("web.apply_domain", applyParams("example.com", "8.3"))

	agentErr := assertCode(t, err, rpc.CodeActionFailed)
	if agentErr.Message != "PHP 8.3 is not installed" {
		t.Fatalf("unexpected message %q", agentErr.Message)
	}

	env.assertUntouched("lookup budi")
}

func TestWebMethodsRefuseTheServerHostname(t *testing.T) {
	for _, method := range []string{"web.apply_domain", "web.remove_domain", "ssl.issue", "ssl.delete"} {
		env := newWebEnv(t)
		params := map[string]string{"username": "budi", "domain": "server1.example.net"}
		if method == "web.apply_domain" {
			params["php_version"] = "8.4"
		}

		_, err := env.call(method, params)

		agentErr := assertCode(t, err, rpc.CodeActionFailed)
		if agentErr.Message != "domain server1.example.net is the hostname of this server" {
			t.Fatalf("%s: unexpected message %q", method, agentErr.Message)
		}

		env.assertUntouched()
	}
}

func TestWebMethodsRefuseADomainOfAnotherAccount(t *testing.T) {
	for _, method := range []string{"web.apply_domain", "web.remove_domain"} {
		for _, domain := range []string{"example.com", "www.example.com"} {
			env := newWebEnv(t)
			env.write(env.cfg.NginxDir + "/sari/example.com.conf")

			params := map[string]string{"username": "budi", "domain": domain}
			if method == "web.apply_domain" {
				params["php_version"] = "8.4"
			}

			_, err := env.call(method, params)

			agentErr := assertCode(t, err, rpc.CodeActionFailed)
			if agentErr.Message != "domain "+domain+" is already used by another account" {
				t.Fatalf("%s %s: unexpected message %q", method, domain, agentErr.Message)
			}

			assertCalls(t, env.system, "lookup budi")

			if len(env.programs) != 0 || len(env.sites) != 0 {
				t.Fatalf("%s %s: the system was changed", method, domain)
			}
		}
	}
}

func TestWebMethodsNeedAnExistingManagedAccount(t *testing.T) {
	params := map[string]map[string]string{
		"web.apply_domain":  applyParams("example.com", "8.4"),
		"web.remove_domain": {"username": "budi", "domain": "example.com"},
		"php.remove_pool":   {"username": "budi", "php_version": "8.4"},
		"ssl.issue":         {"username": "budi", "domain": "example.com"},
		"ssl.delete":        {"username": "budi", "domain": "example.com"},
	}

	for method, methodParams := range params {
		env := newWebEnv(t)
		delete(env.system.users, "budi")

		_, err := env.call(method, methodParams)

		agentErr := assertCode(t, err, rpc.CodeActionFailed)
		if agentErr.Message != "account budi does not exist" {
			t.Fatalf("%s: unexpected message %q", method, agentErr.Message)
		}

		env.assertUntouched("lookup budi")

		env = newWebEnv(t)
		env.system.users["budi"] = sysuser.User{Name: "budi", UID: 1001, GID: 1001, Comment: "Budi", Home: "/home/budi"}

		_, err = env.call(method, methodParams)

		agentErr = assertCode(t, err, rpc.CodeActionFailed)
		if agentErr.Message != "username budi is already used by a system account" {
			t.Fatalf("%s: unexpected message %q", method, agentErr.Message)
		}

		env.assertUntouched("lookup budi")
	}
}

func TestApplyDomainReportsARejectedConfiguration(t *testing.T) {
	env := newWebEnv(t)
	env.failing = []string{"apache2ctl"}

	_, err := env.call("web.apply_domain", applyParams("example.com", "8.4"))

	agentErr := assertCode(t, err, rpc.CodeActionFailed)
	if agentErr.Message != "the web server rejected the configuration for example.com" {
		t.Fatalf("unexpected message %q", agentErr.Message)
	}

	if _, err := os.Stat(env.cfg.NginxDir + "/budi/example.com.conf"); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("the rejected vhost was kept (%v)", err)
	}
}

func TestApplyDomainReportsARejectedPool(t *testing.T) {
	env := newWebEnv(t)
	env.failing = []string{"php-fpm8.4"}

	_, err := env.call("web.apply_domain", applyParams("example.com", "8.4"))

	agentErr := assertCode(t, err, rpc.CodeActionFailed)
	if agentErr.Message != "PHP-FPM rejected the pool configuration for example.com" {
		t.Fatalf("unexpected message %q", agentErr.Message)
	}
}

func TestApplyDomainReportsFoldersInTheWay(t *testing.T) {
	env := newWebEnv(t)
	env.cfg.PrepareSite = func(context.Context, sysuser.Site) error {
		return errors.Join(errors.New("open /home/budi/domains/example.com"), sysuser.ErrInTheWay)
	}
	env.web = webserver.New(env.cfg)

	_, err := env.call("web.apply_domain", applyParams("example.com", "8.4"))

	agentErr := assertCode(t, err, rpc.CodeActionFailed)
	if !strings.Contains(agentErr.Message, "example.com") || !strings.Contains(agentErr.Message, "in the way") {
		t.Fatalf("unexpected message %q", agentErr.Message)
	}
}

func TestRemoveDomainRemovesTheVhosts(t *testing.T) {
	env := newWebEnv(t)

	if _, err := env.call("web.apply_domain", applyParams("example.com", "8.4")); err != nil {
		t.Fatal(err)
	}

	result, err := env.call("web.remove_domain", map[string]string{"username": "budi", "domain": "example.com"})
	if err != nil || string(result) != `{}` {
		t.Fatalf("remove: %s, %v", result, err)
	}

	for _, path := range []string{env.cfg.NginxDir + "/budi/example.com.conf", env.cfg.ApacheDir + "/budi/example.com.conf"} {
		if _, err := os.Stat(path); !errors.Is(err, os.ErrNotExist) {
			t.Fatalf("%s still exists (%v)", path, err)
		}
	}

	// Removing it again succeeds.
	if _, err := env.call("web.remove_domain", map[string]string{"username": "budi", "domain": "example.com"}); err != nil {
		t.Fatalf("second remove failed: %v", err)
	}
}

func TestRemovePool(t *testing.T) {
	env := newWebEnv(t)

	if _, err := env.call("web.apply_domain", applyParams("example.com", "8.4")); err != nil {
		t.Fatal(err)
	}

	pool := env.cfg.PoolDir + "/8.4/budi.conf"

	// Still used by example.com: kept, and the call succeeds.
	result, err := env.call("php.remove_pool", map[string]string{"username": "budi", "php_version": "8.4"})
	if err != nil || string(result) != `{}` {
		t.Fatalf("remove pool in use: %s, %v", result, err)
	}

	if _, err := os.Stat(pool); err != nil {
		t.Fatalf("a pool in use was removed: %v", err)
	}

	if _, err := env.call("web.remove_domain", map[string]string{"username": "budi", "domain": "example.com"}); err != nil {
		t.Fatal(err)
	}

	result, err = env.call("php.remove_pool", map[string]string{"username": "budi", "php_version": "8.4"})
	if err != nil || string(result) != `{}` {
		t.Fatalf("remove pool: %s, %v", result, err)
	}

	if _, err := os.Stat(pool); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("the pool still exists (%v)", err)
	}

	// A missing pool succeeds too.
	if _, err := env.call("php.remove_pool", map[string]string{"username": "budi", "php_version": "8.4"}); err != nil {
		t.Fatalf("second remove pool failed: %v", err)
	}
}

func TestRemovePoolNeedsAnInstalledPHP(t *testing.T) {
	env := newWebEnv(t)

	_, err := env.call("php.remove_pool", map[string]string{"username": "budi", "php_version": "7.4"})

	agentErr := assertCode(t, err, rpc.CodeActionFailed)
	if agentErr.Message != "PHP 7.4 is not installed" {
		t.Fatalf("unexpected message %q", agentErr.Message)
	}

	env.assertUntouched("lookup budi")
}

func TestWebSystemFailuresStayInternal(t *testing.T) {
	env := newWebEnv(t)
	env.failing = []string{"systemctl"}

	_, err := env.call("web.apply_domain", applyParams("example.com", "8.4"))
	if err == nil {
		t.Fatal("expected an error")
	}

	if _, ok := errors.AsType[*rpc.Error](err); ok {
		t.Fatalf("system details would reach the panel: %v", err)
	}
}

func TestWebMethodsOnUnsupportedPlatformsGetAReadableError(t *testing.T) {
	if runtime.GOOS == "linux" {
		t.Skip("this would change the real system")
	}

	params := map[string]map[string]string{
		"web.apply_domain":  applyParams("example.com", "8.4"),
		"web.remove_domain": {"username": "budi", "domain": "example.com"},
		"php.remove_pool":   {"username": "budi", "php_version": "8.4"},
		"ssl.issue":         {"username": "budi", "domain": "example.com"},
		"ssl.delete":        {"username": "budi", "domain": "example.com"},
	}

	for method, methodParams := range params {
		raw, _ := json.Marshal(methodParams)
		registered := webMethods(sysuser.System{}, webserver.New(webserver.DefaultConfig()))[method]

		_, err := registered.Handler(context.Background(), raw)

		agentErr := assertCode(t, err, rpc.CodeActionFailed)
		if agentErr.Message != "accounts can only be managed on Linux" {
			t.Fatalf("%s: unexpected message %q", method, agentErr.Message)
		}
	}
}

func TestApplyDomainGivesTheWebsiteItsPHPSettings(t *testing.T) {
	env := newWebEnv(t)

	params := map[string]any{
		"username":     "budi",
		"domain":       "example.com",
		"php_version":  "8.4",
		"php_settings": map[string]string{"memory_limit": "512M", "date.timezone": "Asia/Jakarta"},
	}

	if _, err := env.call("web.apply_domain", params); err != nil {
		t.Fatalf("apply failed: %v", err)
	}

	pool, err := os.ReadFile(env.cfg.PoolDir + "/8.4/budi.d/example.com.conf")
	if err != nil || !strings.Contains(string(pool), "php_value[memory_limit] = 512M\n") ||
		!strings.Contains(string(pool), "php_value[date.timezone] = Asia/Jakarta\n") {
		t.Fatalf("the settings are missing from the pool (%v):\n%s", err, pool)
	}
}

// manyDomains returns count different domains.
func manyDomains(count int) []string {
	domains := make([]string, count)
	for i := range domains {
		domains[i] = fmt.Sprintf("d%d.example.com", i)
	}

	return domains
}
