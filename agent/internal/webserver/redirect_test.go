package webserver

import (
	"slices"
	"strconv"
	"strings"
	"testing"

	"nusapanel/agent/internal/sysuser"
)

func TestApplyAliasServesTheFolderOfItsParent(t *testing.T) {
	env := newEnv(t)

	alias := budiSite
	alias.Domain = "example.net"
	alias.Kind = KindAlias
	alias.Parent = "example.com"

	root, err := env.apply(alias)
	if err != nil {
		t.Fatalf("apply failed: %v", err)
	}

	if root != "/home/budi/domains/example.com/public_html" {
		t.Fatalf("document root = %q", root)
	}

	if len(env.sites) != 1 || len(env.sites[0].Index) != 0 ||
		!slices.Equal(env.sites[0].Dirs, []sysuser.Dir{{Name: "domains", Mode: 0o711}, {Name: "example.com", Mode: 0o711}, {Name: "public_html", Mode: 0o755}}) {
		t.Fatalf("unexpected site folders %+v", env.sites)
	}

	nginx := env.read(env.nginxVhost("budi", "example.net"))
	assertContains(t, "nginx vhost", nginx,
		"server_name example.net www.example.net;",
		"access_log "+env.cfg.LogDir+"/example.net.access.log;",
		"root /home/budi/domains/example.com/public_html;",
		"location / {\n        proxy_pass http://127.0.0.1:8080;",
	)

	assertContains(t, "apache vhost", env.read(env.apacheVhost("budi", "example.net")),
		"    ServerName example.net\n    ServerAlias www.example.net\n",
		"    DocumentRoot /home/budi/domains/example.com/public_html\n",
		"proxy:unix:/run/nusa-php/php84-budi.sock|fcgi://php84-budi",
	)
}

func TestApplyForwardRedirectsEveryRequestToItsParent(t *testing.T) {
	env := newEnv(t)

	alias := budiSite
	alias.Domain = "example.net"
	alias.Kind = KindAlias
	alias.Parent = "example.com"
	env.mustApply(alias)
	env.runner.reset()
	env.sites = nil

	forward := alias
	forward.Kind = KindForward

	root, err := env.apply(forward)
	if err != nil {
		t.Fatalf("apply failed: %v", err)
	}

	if root != "" {
		t.Fatalf("a forward has document root %q", root)
	}

	// No folders and no PHP: only the web servers change.
	env.assertCalls(reloadBoth...)

	if len(env.sites) != 0 {
		t.Fatalf("folders were prepared: %+v", env.sites)
	}

	nginx := env.read(env.nginxVhost("budi", "example.net"))
	assertContains(t, "nginx vhost", nginx,
		"server_name example.net www.example.net;",
		"location ^~ /.well-known/acme-challenge/ {",
		"include "+env.cfg.NginxDir+"/budi/suspended*.inc;",
		"    location / {\n        return 301 $scheme://example.com$request_uri;\n    }\n}",
	)
	assertNotContains(t, "nginx vhost", nginx, "root /home", "proxy_pass", "<no value>")

	// The Apache vhost it had as an alias is gone.
	env.assertMissing(env.apacheVhost("budi", "example.net"))
}

func TestRenderedForwardWithForcedHTTPS(t *testing.T) {
	env := newEnv(t)
	env.writeCertificate("example.net", "example.net")

	forward := budiSite
	forward.Domain = "example.net"
	forward.Kind = KindForward
	forward.Parent = "example.com"
	forward.SSL = true
	forward.ForceHTTPS = true
	env.mustApply(forward)

	nginx := env.read(env.nginxVhost("budi", "example.net"))

	if strings.Count(nginx, "return 301 https://$host$request_uri;") != 1 ||
		strings.Count(nginx, "return 301 $scheme://example.com$request_uri;") != 1 {
		t.Fatalf("port 80 must go to HTTPS and port 443 to the parent:\n%s", nginx)
	}
}

func TestRenderedRedirects(t *testing.T) {
	env := newEnv(t)

	site := budiSite
	site.Redirects = []Redirect{
		{Path: "/promo", Destination: "https://shop.example.net/sale", Status: 301},
		{Path: "/", Destination: "https://new.example.org", Status: 302, Wildcard: true},
		{Path: "/docs/v1.2", Destination: "https://example.org/docs?from=old", Status: 301},
		{Path: "/blog", Destination: "https://blog.example.com/", Status: 301, Wildcard: true},
	}
	env.mustApply(site)

	nginx := env.read(env.nginxVhost("budi", "example.com"))

	blocks := []string{
		"    # Redirect /docs/v1.2.\n" +
			"    location ~ ^/docs/v1\\.2/?$ {\n" +
			"        return 301 \"https://example.org/docs?from=old\";\n" +
			"    }",
		"    # Redirect /promo.\n" +
			"    location ~ ^/promo/?$ {\n" +
			"        return 301 \"https://shop.example.net/sale$is_args$args\";\n" +
			"    }",
		"    # Redirect /blog and everything under it.\n" +
			"    location ~ ^/blog(?:/.*)?$ {\n" +
			"        if ($request_uri ~ \"^/blog(?:/(?<nusa_rest>[^?]*))?\") {\n" +
			"            return 301 \"https://blog.example.com/$nusa_rest$is_args$args\";\n" +
			"        }\n" +
			"        return 301 \"https://blog.example.com/$is_args$args\";\n" +
			"    }",
		"    # Redirect / and everything under it.\n" +
			"    location ~ ^(?:/.*)?$ {\n" +
			"        if ($request_uri ~ \"^(?:/(?<nusa_rest>[^?]*))?\") {\n" +
			"            return 302 \"https://new.example.org/$nusa_rest$is_args$args\";\n" +
			"        }\n" +
			"        return 302 \"https://new.example.org/$is_args$args\";\n" +
			"    }",
	}

	// Nginx uses the first regular expression that matches, so the longest
	// path comes first, and all come before the site's own locations.
	last := strings.Index(nginx, "include "+env.cfg.NginxDir+"/budi/suspended*.inc;")

	for _, block := range append(blocks, "location ~ /\\.ht {") {
		index := strings.Index(nginx, block)
		if index <= last {
			t.Fatalf("block missing or out of order:\n%s\n\nin:\n%s", block, nginx)
		}

		last = index
	}

	if strings.Count(nginx, "{") != strings.Count(nginx, "}") {
		t.Fatalf("unbalanced braces:\n%s", nginx)
	}
}

func TestAnAliasCanHaveRedirectsOfItsOwn(t *testing.T) {
	env := newEnv(t)

	alias := budiSite
	alias.Domain = "example.net"
	alias.Kind = KindAlias
	alias.Parent = "example.com"
	alias.Redirects = []Redirect{{Path: "/promo", Destination: "https://example.com/sale", Status: 302}}
	env.mustApply(alias)

	assertContains(t, "alias vhost", env.read(env.nginxVhost("budi", "example.net")),
		"location ~ ^/promo/?$ {\n        return 302 \"https://example.com/sale$is_args$args\";")
}

func TestSiteValidateRefusesUnsafeValues(t *testing.T) {
	redirect := func(path, destination string) Redirect {
		return Redirect{Path: path, Destination: destination, Status: 301}
	}

	tooMany := make([]Redirect, MaxRedirects+1)
	for i := range tooMany {
		tooMany[i] = redirect("/p"+strconv.Itoa(i), "https://example.org/")
	}

	invalid := map[string]Site{
		"unknown kind":            {Kind: "proxy"},
		"alias without parent":    {Kind: KindAlias},
		"alias with a bad parent": {Kind: KindAlias, Parent: "example.org;"},
		"pointer to itself":       {Kind: KindForward, Parent: "example.com"},
		"domain with a parent":    {Kind: KindDomain, Parent: "example.org"},
		"forward with redirects":  {Kind: KindForward, Parent: "example.org", Redirects: []Redirect{redirect("/a", "https://example.org/")}},
		"too many redirects":      {Redirects: tooMany},
		"same path twice":         {Redirects: []Redirect{redirect("/a", "https://example.org/"), redirect("/a", "https://example.net/")}},
		"status 307":              {Redirects: []Redirect{{Path: "/a", Destination: "https://example.org/", Status: 307}}},
		"wildcard with a query":   {Redirects: []Redirect{{Path: "/a", Destination: "https://example.org/?x=1", Status: 301, Wildcard: true}}},
		"wildcard with fragment":  {Redirects: []Redirect{{Path: "/a", Destination: "https://example.org/#top", Status: 301, Wildcard: true}}},
	}

	for _, path := range []string{"", "promo", "/promo/", "//", "/a//b", "/a/../b", "/./a", "/a b", "/a;b", "/$uri", "/a\"b", "/a{b}", "/a%20b", "/a\nb", "/" + strings.Repeat("a", 255)} {
		invalid["path "+strconv.Quote(path)] = Site{Redirects: []Redirect{redirect(path, "https://example.org/")}}
	}

	for _, destination := range []string{
		"", "example.org", "//example.org", "ftp://example.org/", "HTTPS://example.org/", "javascript:alert(1)",
		"https://", "https://example.org/a b", "https://example.org/\"", "https://example.org/$host",
		"https://example.org/;return 200", "https://example.org/{x}", "https://example.org/a\\b",
		"https://example.org/'", "https://example.org/\nlisten 81", "https://user:pass@example.org/",
		"https://exa_mple.org/", "https://example.org/" + strings.Repeat("a", 2048),
	} {
		invalid["destination "+strconv.Quote(destination)] = Site{Redirects: []Redirect{redirect("/a", destination)}}
	}

	for name, fields := range invalid {
		t.Run(name, func(t *testing.T) {
			env := newEnv(t)

			site := budiSite
			site.Kind = fields.Kind
			site.Parent = fields.Parent
			site.Redirects = fields.Redirects

			if err := site.Validate(); err == nil {
				t.Fatal("Validate accepted it")
			}

			if _, err := env.apply(site); err == nil {
				t.Fatal("ApplyDomain accepted it")
			}

			env.assertCalls()
			env.assertMissing(env.nginxVhost("budi", "example.com"))
		})
	}
}

func TestSiteValidateAcceptsValidRedirects(t *testing.T) {
	site := budiSite
	site.Redirects = []Redirect{
		{Path: "/", Destination: "https://example.org", Status: 301},
		{Path: "/Promo_2026/a.b~c-d", Destination: "http://203.0.113.5:8080/x?y=1&z=(2)#top", Status: 302},
		{Path: "/shop", Destination: "https://[2001:db8::1]/", Status: 301, Wildcard: true},
		{Path: "/me", Destination: "https://medium.com/@budi/post-1", Status: 301},
	}

	if err := site.Validate(); err != nil {
		t.Fatalf("Validate refused valid redirects: %v", err)
	}
}
