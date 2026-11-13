package webserver

import (
	"context"
	"errors"
	"os"
	"strings"
	"testing"
	"time"
)

const loginCertonly = "certbot certonly --webroot -w $BASE/var/www/rayxo-acme --cert-name rayxo-login_panel.webku.id -d panel.webku.id " +
	"--non-interactive --agree-tos --keep-until-expiring --register-unsafely-without-email"

// loginEnv is a test environment with the panel's snippet, whose clock is
// two months before the fake certificates expire.
func loginEnv(t *testing.T) *testEnv {
	t.Helper()

	e := newEnv(t)
	e.manager.cfg.Now = func() time.Time { return testExpiry.AddDate(0, -2, 0) }
	e.write(e.cfg.PanelSnippet, "root /usr/local/rayxo/current/panel/public;\n")

	return e
}

func TestALoginDomainServesThePanelWithItsOwnCertificate(t *testing.T) {
	e := loginEnv(t)
	e.reach(nil, "panel.webku.id")

	login, err := e.manager.ApplyLoginDomain(context.Background(), "panel.webku.id")
	if err != nil {
		t.Fatal(err)
	}

	if login.Domain != "panel.webku.id" || !login.ExpiresAt.Equal(testExpiry) {
		t.Fatalf("login = %+v", login)
	}

	e.assertCalls(loginCertonly, "nginx -t", "systemctl reload nginx")
	e.assertNoTokens()

	site := e.read(e.cfg.SitesDir + "/rayxo-login-panel.webku.id.conf")
	assertContains(t, "login site", site,
		"    listen 80;\n    server_name panel.webku.id;",
		"location ^~ /.well-known/acme-challenge/ {\n        root "+e.cfg.AcmeDir+";",
		"return 301 https://$host$request_uri;",
		"    listen 443 ssl http2;\n    server_name panel.webku.id;",
		"ssl_certificate "+e.cfg.LetsEncryptDir+"/live/rayxo-login_panel.webku.id/fullchain.pem;",
		"include "+e.cfg.PanelSnippet+";",
	)
	assertNotContains(t, "login site", site, "<no value>", "proxy_pass")

	if !e.manager.HasLoginDomain("panel.webku.id") {
		t.Error("the login domain is not reported")
	}

	// Applied again while the certificate is valid for long, certbot is not run.
	e.runner.reset()
	if _, err := e.manager.ApplyLoginDomain(context.Background(), "panel.webku.id"); err != nil {
		t.Fatal(err)
	}

	e.assertCalls("nginx -t", "systemctl reload nginx")
}

func TestALoginDomainMustReachThisServer(t *testing.T) {
	e := loginEnv(t)

	_, err := e.manager.ApplyLoginDomain(context.Background(), "panel.webku.id")
	if _, ok := errors.AsType[*UnreachableError](err); !ok {
		t.Fatalf("err = %v, want an UnreachableError", err)
	}

	e.assertCalls()

	if _, err := os.Stat(e.cfg.SitesDir + "/rayxo-login-panel.webku.id.conf"); !os.IsNotExist(err) {
		t.Error("a site was written")
	}
}

func TestTheServerNameAndWebsitesCannotBeLoginDomains(t *testing.T) {
	e := loginEnv(t)
	e.mustApply(budiSite)
	e.runner.reset()

	for _, domain := range []string{"server1.example.net", "panel.server1.example.net", "example.com"} {
		if _, err := e.manager.ApplyLoginDomain(context.Background(), domain); !errors.Is(err, ErrDomainTaken) {
			t.Errorf("ApplyLoginDomain(%s) = %v, want ErrDomainTaken", domain, err)
		}
	}

	e.assertCalls()
}

func TestALoginDomainNeedsThePanelSnippet(t *testing.T) {
	e := newEnv(t)
	e.reach(nil, "panel.webku.id")

	_, err := e.manager.ApplyLoginDomain(context.Background(), "panel.webku.id")
	if err == nil || !strings.Contains(err.Error(), "run setup.sh again") {
		t.Fatalf("err = %v", err)
	}

	e.assertCalls()
}

func TestARejectedLoginSiteIsRolledBack(t *testing.T) {
	e := loginEnv(t)
	e.writeCertificate("rayxo-login_panel.webku.id", "panel.webku.id")
	e.runner.fail = []string{"nginx -t"}

	if _, err := e.manager.ApplyLoginDomain(context.Background(), "panel.webku.id"); !errors.Is(err, ErrConfigRejected) {
		t.Fatalf("err = %v, want ErrConfigRejected", err)
	}

	if e.manager.HasLoginDomain("panel.webku.id") {
		t.Error("the rejected site stayed")
	}
}

func TestRemovingALoginDomainRemovesItsSiteAndCertificate(t *testing.T) {
	e := loginEnv(t)
	e.reach(nil, "panel.webku.id")

	if _, err := e.manager.ApplyLoginDomain(context.Background(), "panel.webku.id"); err != nil {
		t.Fatal(err)
	}

	e.runner.reset()

	if err := e.manager.RemoveLoginDomain(context.Background(), "panel.webku.id"); err != nil {
		t.Fatal(err)
	}

	if e.manager.HasLoginDomain("panel.webku.id") {
		t.Error("the site stayed")
	}

	e.assertCalls("nginx -t", "systemctl reload nginx", deleteCall("rayxo-login_panel.webku.id"))

	// Removing it again is not an error.
	if err := e.manager.RemoveLoginDomain(context.Background(), "panel.webku.id"); err != nil {
		t.Fatal(err)
	}
}
