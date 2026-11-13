package actions

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"rayxo/agent/internal/rpc"
)

func (e *webEnv) callLogin(method string, params any) (json.RawMessage, error) {
	e.t.Helper()

	raw, err := json.Marshal(params)
	if err != nil {
		e.t.Fatal(err)
	}

	registered, ok := loginMethods(e.web)[method]
	if !ok {
		e.t.Fatalf("unknown method %s", method)
	}

	if _, err := registered.LockKey(raw); err != nil {
		return nil, err
	}

	result, err := registered.Handler(context.Background(), raw)
	if err != nil {
		return nil, err
	}

	return json.Marshal(result)
}

func (e *webEnv) panelSnippet() {
	e.t.Helper()

	if err := os.MkdirAll(filepath.Dir(e.cfg.PanelSnippet), 0o755); err != nil {
		e.t.Fatal(err)
	}

	if err := os.WriteFile(e.cfg.PanelSnippet, []byte("root /usr/local/rayxo/current/panel/public;\n"), 0o644); err != nil {
		e.t.Fatal(err)
	}
}

func TestLoginDomainMethodsAreRegistered(t *testing.T) {
	registry := rpc.NewRegistry()
	Register(registry, Config{})

	for _, name := range []string{"panel.add_login_domain", "panel.remove_login_domain"} {
		if !slices.Contains(registry.Names(), name) {
			t.Errorf("method %s is not registered", name)
		}
	}
}

func TestALoginDomainServesThePanelAndNoWebsite(t *testing.T) {
	env := newWebEnv(t)
	env.panelSnippet()
	env.reach("panel.webku.id")

	result, err := env.callLogin("panel.add_login_domain", map[string]string{"domain": "panel.webku.id"})
	if err != nil || !strings.HasPrefix(string(result), `{"domain":"panel.webku.id","expires_at":`) {
		t.Fatalf("result = %s, %v", result, err)
	}

	if _, err := os.Stat(filepath.Join(env.cfg.SitesDir, "rayxo-login-panel.webku.id.conf")); err != nil {
		t.Fatal(err)
	}

	env.assertMessage("web.apply_domain", applyParams("panel.webku.id", "8.4"), "domain panel.webku.id is a reseller's sign-in address")

	if _, err := env.callLogin("panel.remove_login_domain", map[string]string{"domain": "panel.webku.id"}); err != nil {
		t.Fatal(err)
	}

	if _, err := os.Stat(filepath.Join(env.cfg.SitesDir, "rayxo-login-panel.webku.id.conf")); !os.IsNotExist(err) {
		t.Fatal("the site stayed")
	}

	env.mustCall("web.apply_domain", applyParams("panel.webku.id", "8.4"))
}

func TestALoginDomainExplainsWhyItCannotServe(t *testing.T) {
	env := newWebEnv(t)
	env.panelSnippet()
	env.mustCall("web.apply_domain", applyParams("example.com", "8.4"))

	for domain, want := range map[string]string{
		"panel.webku.id":      "panel.webku.id does not reach this server over HTTP (no DNS record); point its A record to this server, without a proxy such as Cloudflare's, then try again",
		"example.com":         "example.com is the server's own name or a website's domain",
		"server1.example.net": "server1.example.net is the server's own name or a website's domain",
	} {
		_, err := env.callLogin("panel.add_login_domain", map[string]string{"domain": domain})

		if agentErr := assertCode(t, err, rpc.CodeActionFailed); agentErr.Message != want {
			t.Errorf("%s: message %q", domain, agentErr.Message)
		}
	}
}

func TestLoginDomainMethodsRejectInvalidParams(t *testing.T) {
	for _, params := range []map[string]any{{}, {"domain": "../etc"}, {"domain": "Panel.webku.id"}, {"domain": "panel.webku.id", "port": 443}} {
		env := newWebEnv(t)

		for _, method := range []string{"panel.add_login_domain", "panel.remove_login_domain"} {
			_, err := env.callLogin(method, params)
			assertCode(t, err, rpc.CodeInvalidParams)
		}

		env.assertUntouched()
	}
}
