package webserver

import (
	"context"
	"errors"
	"os"
	"strconv"
	"strings"
	"testing"
)

const (
	testHash      = "$6$abcdefgh12345678$H.n1EWQmWoEgsATLJ.dVjWe9Vj1ZCEW73beozLP7CEywnTxqwob8sTUvv52IPEcyMI/6uWNNTntLRkDaTMPKS/"
	otherTestHash = "$6$rounds=5000$saltsalt$289Zq9vglTd1xdnDcLJOgENgzf90K/ngvaPiNHqKWC/cmLlUOWDT8xCZ2LO9vcqLz9uC1JWaD15YZNTnbF0Jk."
)

// assertInOrder fails unless every block is in content, each after the one
// before it.
func assertInOrder(t *testing.T, content string, blocks ...string) {
	t.Helper()

	last := -1

	for _, block := range blocks {
		index := strings.Index(content[last+1:], block)
		if index < 0 {
			t.Fatalf("block missing or out of order:\n%s\n\nin:\n%s", block, content)
		}

		last += 1 + index
	}
}

func TestRenderedErrorPages(t *testing.T) {
	env := newEnv(t)

	site := budiSite
	site.ErrorPages = []int{503, 404, 401}
	env.mustApply(site)

	nginx := env.read(env.nginxVhost("budi", "example.com"))
	assertContains(t, "nginx vhost", nginx,
		"    error_page 401 /.errors/401.html;\n"+
			"    error_page 404 /.errors/404.html;\n"+
			"    error_page 502 503 504 /.errors/503.html;\n",
		// Never served on its own, so a script there cannot leak.
		"    location ^~ /.errors/ {\n        internal;\n        try_files $uri =404;\n    }",
	)

	apache := env.read(env.apacheVhost("budi", "example.com"))
	assertContains(t, "apache vhost", apache,
		"    ErrorDocument 401 /.errors/401.html\n"+
			"    ErrorDocument 404 /.errors/404.html\n"+
			"    ErrorDocument 503 /.errors/503.html\n",
	)
	// Without a protected directory nothing needs opening up.
	assertNotContains(t, "apache vhost", apache, "<Directory", "<no value>")

	if strings.Count(nginx, "{") != strings.Count(nginx, "}") {
		t.Fatalf("unbalanced braces:\n%s", nginx)
	}
}

func TestProtectedDirectoriesAskForALoginInBothWebServers(t *testing.T) {
	env := newEnv(t)

	site := budiSite
	site.ErrorPages = []int{401}
	site.ProtectedDirectories = []ProtectedDirectory{
		{Path: "/admin.v2", Realm: "Admin area", Users: []ProtectedUser{{Name: "budi", Hash: testHash}, {Name: "siti.a", Hash: otherTestHash}}},
		{Path: "/", Realm: "Staging", Users: nil},
	}
	site.MIMETypes = []MIMEType{{Extension: "mp4", Type: "video/x-custom"}}
	env.mustApply(site)

	admin := env.manager.htpasswdFile("budi", "example.com", "/admin.v2")
	whole := env.manager.htpasswdFile("budi", "example.com", "/")

	if got := env.read(admin); got != "budi:"+testHash+"\nsiti.a:"+otherTestHash+"\n" {
		t.Fatalf("user file of /admin.v2 = %q", got)
	}

	if got := env.read(whole); got != "" {
		t.Fatalf("a directory without users must let nobody in, got %q", got)
	}

	for _, path := range []string{admin, whole} {
		info, err := os.Stat(path)
		if err != nil || info.Mode().Perm() != 0o640 {
			t.Fatalf("%s must be readable by root and the web servers only (%v, %v)", path, info, err)
		}
	}

	nginx := env.read(env.nginxVhost("budi", "example.com"))

	// After Apache's own files, which stay refused, and before the static
	// files, which would otherwise be served without a login.
	assertInOrder(t, nginx,
		"location ~ /\\.(?:ht|ftpquota$) {",
		"    # /admin.v2 and everything in it ask for a username and password.\n"+
			"    location ~ ^/admin\\.v2(?:/|$) {\n"+
			"        auth_basic \"Admin area\";\n"+
			"        auth_basic_user_file "+admin+";\n",
		"        location ~* \\.(?:css|js|",
		"            location ~* \\.mp4$ {\n                types {}\n                default_type video/x-custom;",
		"proxy_pass http://127.0.0.1:8080;",
		"    location ~ ^/ {\n        auth_basic \"Staging\";\n        auth_basic_user_file "+whole+";\n",
		"\n    location ~* \\.(?:css|js|",
		"\n    location / {\n",
	)

	apache := env.read(env.apacheVhost("budi", "example.com"))
	assertContains(t, "apache vhost", apache,
		"    <Directory \"/home/budi/domains/example.com/public_html/admin.v2\">\n"+
			"        AuthType Basic\n"+
			"        AuthName \"Admin area\"\n"+
			"        AuthUserFile "+admin+"\n"+
			"        Require valid-user\n"+
			"    </Directory>",
		"    <Directory \"/home/budi/domains/example.com/public_html\">\n        AuthType Basic\n        AuthName \"Staging\"\n",
		// The page of a wrong login shows without one.
		"    <Directory \"/home/budi/domains/example.com/public_html/.errors\">\n        Require all granted\n    </Directory>",
		"    AddType video/x-custom .mp4\n",
	)

	if strings.Count(nginx, "{") != strings.Count(nginx, "}") {
		t.Fatalf("unbalanced braces:\n%s", nginx)
	}

	// The file of a domain whose name only starts the same is not touched.
	neighbour := budiSite
	neighbour.Domain = "example.com.au"
	neighbour.ProtectedDirectories = []ProtectedDirectory{{Path: "/admin.v2", Realm: "Other", Users: []ProtectedUser{{Name: "x", Hash: testHash}}}}
	env.mustApply(neighbour)

	neighbourFile := env.manager.htpasswdFile("budi", "example.com.au", "/admin.v2")

	// A directory that is no longer protected loses its file.
	site.ProtectedDirectories = site.ProtectedDirectories[1:]
	env.mustApply(site)
	env.assertMissing(admin)
	env.read(whole)
	env.read(neighbourFile)

	if err := env.manager.RemoveDomain(context.Background(), "budi", "example.com"); err != nil {
		t.Fatalf("remove failed: %v", err)
	}

	env.assertMissing(whole)
	env.read(neighbourFile)
}

func TestARejectedConfigurationPutsTheUserFilesBack(t *testing.T) {
	env := newEnv(t)

	site := budiSite
	site.ProtectedDirectories = []ProtectedDirectory{{Path: "/admin", Realm: "Admin", Users: []ProtectedUser{{Name: "budi", Hash: testHash}}}}
	env.mustApply(site)

	file := env.manager.htpasswdFile("budi", "example.com", "/admin")
	env.runner.fail = []string{"nginx -t"}

	changed := site
	changed.ProtectedDirectories = []ProtectedDirectory{{Path: "/admin", Realm: "Admin", Users: []ProtectedUser{{Name: "siti", Hash: otherTestHash}}}}

	if _, err := env.apply(changed); !errors.Is(err, ErrConfigRejected) {
		t.Fatalf("got %v, want ErrConfigRejected", err)
	}

	info, err := os.Stat(file)
	if err != nil || info.Mode().Perm() != 0o640 || env.read(file) != "budi:"+testHash+"\n" {
		t.Fatalf("the user file was not put back as it was (%v, %v)", info, err)
	}

	removed := site
	removed.ProtectedDirectories = nil

	if _, err := env.apply(removed); !errors.Is(err, ErrConfigRejected) {
		t.Fatalf("got %v, want ErrConfigRejected", err)
	}

	if env.read(file) != "budi:"+testHash+"\n" {
		t.Fatal("the removed user file was not put back")
	}
}

func TestRenderedHotlinkProtection(t *testing.T) {
	env := newEnv(t)

	site := budiSite
	site.Hotlink = &Hotlink{Extensions: []string{"jpg", "png"}, Domains: []string{"partner.example.org"}, AllowDirect: true}
	env.mustApply(site)

	nginx := env.read(env.nginxVhost("budi", "example.com"))
	assertContains(t, "nginx vhost", nginx,
		"    valid_referers none blocked server_names *.example.com partner.example.org *.partner.example.org;\n"+
			"    set $rayxo_hotlink \"\";\n"+
			"    if ($uri ~* \"\\.(?:jpg|png)$\") {\n"+
			"        set $rayxo_hotlink $invalid_referer;\n"+
			"    }\n"+
			"    if ($rayxo_hotlink) {\n"+
			"        return 403;\n"+
			"    }\n",
	)

	// Checked before any location, so no location can serve the file.
	assertInOrder(t, nginx, "suspended*.inc;", "valid_referers", "location ~ /\\.(?:ht|ftpquota$)")

	site.Hotlink = &Hotlink{Extensions: []string{"mp4"}, RedirectTo: "https://cdn.example.net/blocked.png"}
	env.mustApply(site)

	nginx = env.read(env.nginxVhost("budi", "example.com"))
	assertContains(t, "nginx vhost", nginx,
		"    valid_referers server_names *.example.com;\n",
		"        return 302 \"https://cdn.example.net/blocked.png\";\n",
	)
}

func TestMIMETypesAndPHPExtensions(t *testing.T) {
	env := newEnv(t)

	site := budiSite
	site.MIMETypes = []MIMEType{{Extension: "foo", Type: "application/x-foo"}, {Extension: "js", Type: "text/javascript"}}
	site.PHPExtensions = []string{"html", "inc"}
	env.mustApply(site)

	nginx := env.read(env.nginxVhost("budi", "example.com"))
	// Nginx only needs the types of the files it serves itself.
	assertContains(t, "nginx vhost", nginx, "location ~* \\.js$ {\n            types {}\n            default_type text/javascript;")
	assertNotContains(t, "nginx vhost", nginx, "foo")

	apache := env.read(env.apacheVhost("budi", "example.com"))
	assertContains(t, "apache vhost", apache,
		"<FilesMatch \"\\.(php|phtml|html|inc)$\">",
		"    AddType application/x-foo .foo\n    AddType text/javascript .js\n",
	)

	// PHP-FPM runs exactly what Apache hands it, and nothing more.
	assertContains(t, "pool section", env.read(env.poolSection("budi", "8.4", "example.com")),
		"security.limit_extensions = .php .phtml .html .inc\n")

	other := budiSite
	other.Domain = "example.org"
	env.mustApply(other)
	assertContains(t, "pool section", env.read(env.poolSection("budi", "8.4", "example.org")),
		"security.limit_extensions = .php .phtml\n")

	// Without PHP the extensions run nothing.
	site.NoPHP = true
	env.mustApply(site)
	assertNotContains(t, "apache vhost", env.read(env.apacheVhost("budi", "example.com")), "html|inc")
}

func TestCGIRunsInApacheAsTheAccount(t *testing.T) {
	env := newEnv(t)

	site := budiSite
	site.CGI = true
	site.ProtectedDirectories = []ProtectedDirectory{{Path: "/admin", Realm: "Admin"}}
	env.mustApply(site)

	// After the protected folders, which still ask for a login, and before
	// the static files, so no script is ever served as text.
	assertInOrder(t, env.read(env.nginxVhost("budi", "example.com")),
		"location ~ ^/admin(?:/|$) {",
		"    location ~ ^/cgi-bin/ {\n        proxy_pass http://127.0.0.1:8080;",
		"\n    location ~* \\.(?:css|js|",
	)

	assertContains(t, "apache vhost", env.read(env.apacheVhost("budi", "example.com")),
		"    SuexecUserGroup budi budi\n"+
			"    <Directory \"/home/budi/domains/example.com/public_html/cgi-bin\">\n"+
			"        Options +ExecCGI\n"+
			"        SetHandler cgi-script\n"+
			"    </Directory>",
	)

	site.CGI = false
	env.mustApply(site)

	assertNotContains(t, "apache vhost", env.read(env.apacheVhost("budi", "example.com")), "Suexec", "ExecCGI")
	assertNotContains(t, "nginx vhost", env.read(env.nginxVhost("budi", "example.com")), "cgi-bin")
}

func TestASuspendedWebsiteShowsTheUnavailablePage(t *testing.T) {
	env := newEnv(t)

	site := budiSite
	site.Suspended = true
	env.mustApply(site)

	nginx := env.read(env.nginxVhost("budi", "example.com"))

	// The same page as a suspended account, before any location.
	assertInOrder(t, nginx,
		"suspended*.inc;",
		"    default_type text/html;\n    return 503 '<!doctype html>",
		"temporarily unavailable",
		"</html>';\n",
		"location ~ /\\.(?:ht|ftpquota$)",
	)

	if err := env.manager.SuspendAccount(context.Background(), "budi"); err != nil {
		t.Fatal(err)
	}

	page := env.read(env.manager.suspendedFile("budi"))
	start := strings.Index(nginx, "return 503 '")
	end := strings.Index(nginx[start:], "';") + start

	if !strings.Contains(page, nginx[start:end]) {
		t.Fatalf("the website and the account show different pages:\n%s\n%s", page, nginx[start:end])
	}

	site.Suspended = false
	env.mustApply(site)
	assertNotContains(t, "nginx vhost", env.read(env.nginxVhost("budi", "example.com")), "return 503")
}

func TestSiteValidateRefusesUnsafeWebSettings(t *testing.T) {
	directory := func(path, realm string, users ...ProtectedUser) Site {
		return Site{ProtectedDirectories: []ProtectedDirectory{{Path: path, Realm: realm, Users: users}}}
	}

	tooManyDirectories := Site{}
	for i := range MaxProtectedDirectories + 1 {
		tooManyDirectories.ProtectedDirectories = append(tooManyDirectories.ProtectedDirectories, ProtectedDirectory{Path: "/d" + strconv.Itoa(i), Realm: "x"})
	}

	tooManyUsers := directory("/a", "x")
	for i := range MaxProtectedUsers + 1 {
		tooManyUsers.ProtectedDirectories[0].Users = append(tooManyUsers.ProtectedDirectories[0].Users, ProtectedUser{Name: "u" + strconv.Itoa(i), Hash: testHash})
	}

	invalid := map[string]Site{
		"error page 418":            {ErrorPages: []int{418}},
		"error page twice":          {ErrorPages: []int{404, 404}},
		"forward with error pages":  {Kind: KindForward, Parent: "example.org", ErrorPages: []int{404}},
		"forward with php ext":      {Kind: KindForward, Parent: "example.org", PHPExtensions: []string{"html"}},
		"forward with cgi":          {Kind: KindForward, Parent: "example.org", CGI: true},
		"forward suspended":         {Kind: KindForward, Parent: "example.org", Suspended: true},
		"too many directories":      tooManyDirectories,
		"too many users":            tooManyUsers,
		"same directory twice":      {ProtectedDirectories: []ProtectedDirectory{{Path: "/a", Realm: "x"}, {Path: "/a", Realm: "y"}}},
		"empty realm":               directory("/a", ""),
		"realm with a quote":        directory("/a", `x" ; allow all; "`),
		"realm with a variable":     directory("/a", "x $host"),
		"realm with a backslash":    directory("/a", `x\`),
		"realm with a newline":      directory("/a", "x\nRequire all granted"),
		"realm with a space at end": directory("/a", "x "),
		"realm too long":            directory("/a", strings.Repeat("x", 65)),
		"user with a colon":         directory("/a", "x", ProtectedUser{Name: "a:b", Hash: testHash}),
		"user with a newline":       directory("/a", "x", ProtectedUser{Name: "a\nb", Hash: testHash}),
		"plain password":            directory("/a", "x", ProtectedUser{Name: "a", Hash: "secret"}),
		"md5 hash":                  directory("/a", "x", ProtectedUser{Name: "a", Hash: "$1$abc$abcdefghijklmnopqrstuv"}),
		"hash with a newline":       directory("/a", "x", ProtectedUser{Name: "a", Hash: testHash + "\nroot:" + testHash}),
		"same user twice":           directory("/a", "x", ProtectedUser{Name: "a", Hash: testHash}, ProtectedUser{Name: "a", Hash: otherTestHash}),
		"hotlink without files":     {Hotlink: &Hotlink{}},
		"hotlink on php":            {Hotlink: &Hotlink{Extensions: []string{"php"}}},
		"hotlink file twice":        {Hotlink: &Hotlink{Extensions: []string{"jpg", "jpg"}}},
		"hotlink bad domain":        {Hotlink: &Hotlink{Extensions: []string{"jpg"}, Domains: []string{"a.com; return 200"}}},
		"hotlink bad redirect":      {Hotlink: &Hotlink{Extensions: []string{"jpg"}, RedirectTo: "https://example.org/$host"}},
		"hotlink redirect to self":  {Hotlink: &Hotlink{Extensions: []string{"jpg"}, RedirectTo: "https://www.example.com/no.png"}},
		"hotlink redirect relative": {Hotlink: &Hotlink{Extensions: []string{"jpg"}, RedirectTo: "/no.png"}},
		"mime bad extension":        {MIMETypes: []MIMEType{{Extension: ".foo", Type: "a/b"}}},
		"mime uppercase":            {MIMETypes: []MIMEType{{Extension: "foo", Type: "Text/Plain"}}},
		"mime with a semicolon":     {MIMETypes: []MIMEType{{Extension: "foo", Type: "text/plain; charset=utf-8"}}},
		"mime with a brace":         {MIMETypes: []MIMEType{{Extension: "foo", Type: "text/x}"}}},
		"mime twice":                {MIMETypes: []MIMEType{{Extension: "foo", Type: "a/b"}, {Extension: "foo", Type: "a/c"}}},
		"php on a static file":      {PHPExtensions: []string{"txt"}},
		"php extension php":         {PHPExtensions: []string{"php"}},
		"php extension twice":       {PHPExtensions: []string{"html", "html"}},
		"php extension with a dot":  {PHPExtensions: []string{"ht.ml"}},
		"php extension regex":       {PHPExtensions: []string{"html)|(.*"}},
	}

	for _, path := range []string{"", "admin", "/admin/", "//", "/a//b", "/../etc", "/./a", "/.git", "/a/.ssh", "/a b", "/a;b", "/a\"b", "/$uri", "/a{b}", "/a\nb", "/" + strings.Repeat("a", 255)} {
		invalid["protected path "+strconv.Quote(path)] = directory(path, "x")
	}

	for name, fields := range invalid {
		t.Run(name, func(t *testing.T) {
			env := newEnv(t)

			site := budiSite
			site.Kind = fields.Kind
			site.Parent = fields.Parent
			site.ErrorPages = fields.ErrorPages
			site.ProtectedDirectories = fields.ProtectedDirectories
			site.Hotlink = fields.Hotlink
			site.MIMETypes = fields.MIMETypes
			site.PHPExtensions = fields.PHPExtensions
			site.CGI = fields.CGI
			site.Suspended = fields.Suspended

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

func TestSiteValidateAcceptsValidWebSettings(t *testing.T) {
	site := budiSite
	site.ErrorPages = ErrorPageStatuses
	site.ProtectedDirectories = []ProtectedDirectory{
		{Path: "/", Realm: "Members only (2026): log in!", Users: []ProtectedUser{{Name: "Budi_1.x-y", Hash: testHash}}},
		{Path: "/wp-admin/sub_dir.v2", Realm: "a", Users: []ProtectedUser{{Name: "a", Hash: otherTestHash}}},
	}
	site.Hotlink = &Hotlink{Extensions: StaticExtensions, Domains: []string{"example.org"}, RedirectTo: "https://example.net/x.png?from=hotlink"}
	site.MIMETypes = []MIMEType{{Extension: "webmanifest", Type: "application/manifest+json"}, {Extension: "7z", Type: "application/x-7z-compressed"}}
	site.PHPExtensions = []string{"html", "htm", "inc"}

	if err := site.Validate(); err != nil {
		t.Fatalf("Validate refused valid settings: %v", err)
	}
}
