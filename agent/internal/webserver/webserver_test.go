package webserver

import (
	"bytes"
	"context"
	"errors"
	"io/fs"
	"log/slog"
	"os"
	"os/user"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"sync"
	"testing"

	"nusapanel/agent/internal/runner"
	"nusapanel/agent/internal/sysuser"
)

var budiSite = Site{User: "budi", UID: 1001, GID: 1001, Domain: "example.com", PHPVersion: "8.4"}

// testEnv is a Manager whose every location is inside a temporary
// directory and whose programs are faked.
type testEnv struct {
	t       *testing.T
	base    string
	cfg     Config
	manager *Manager
	runner  *fakeRunner
	logs    *bytes.Buffer

	mu    sync.Mutex
	sites []sysuser.Site
	// siteErr, when set, is returned by PrepareSite.
	siteErr error
	// hosts says where the reachability check's connections to a host
	// name go: an address, or an error. Other names have no DNS record.
	hosts map[string]any
}

func newEnv(t *testing.T) *testEnv {
	t.Helper()

	base := t.TempDir()
	env := &testEnv{t: t, base: base, runner: newFakeRunner(), logs: &bytes.Buffer{}, hosts: map[string]any{}}
	env.runner.base = base
	env.runner.certbot = env.fakeCertbot

	env.cfg = Config{
		NginxDir:  filepath.Join(base, "etc/nginx/nusapanel"),
		ApacheDir: filepath.Join(base, "etc/apache2/nusapanel"),
		PoolDir:   filepath.Join(base, "etc/nusapanel/php-fpm"),
		UnitDir:   filepath.Join(base, "etc/systemd/system"),
		LogDir:    filepath.Join(base, "var/log/nusapanel/domains"),
		LogGroup:  currentGroup(t),
		RunDir:    "/run/nusa-php",
		HomeRoot:  "/home",
		PHPFPMDir: filepath.Join(base, "usr/sbin"),
		// Set before the other locations below, which stay as they were.
		CustomCertificateDir: filepath.Join(base, "etc/nusapanel/ssl"),
		IPv6File:             filepath.Join(base, "if_inet6"),
		Backend:              "127.0.0.1:8080",
		AcmeDir:              filepath.Join(base, "var/www/nusapanel-acme"),
		// The same layout as /etc/letsencrypt.
		LetsEncryptDir: filepath.Join(base, "etc/letsencrypt"),
		Hostnames:      func() []string { return []string{"server1.example.net", "server1"} },
		Run:            env.runner.run,
		DialHTTP:       env.dial,
		PrepareSite: func(_ context.Context, site sysuser.Site) error {
			env.mu.Lock()
			defer env.mu.Unlock()

			env.sites = append(env.sites, site)

			return env.siteErr
		},
		Logger: slog.New(slog.NewTextHandler(env.logs, nil)),
	}

	env.installPHP("8.4")
	env.manager = New(env.cfg)

	return env
}

// currentGroup returns the name of the test user's primary group, the only
// group a test without root can give a directory.
func currentGroup(t *testing.T) string {
	group, err := user.LookupGroupId(strconv.Itoa(os.Getgid()))
	if err != nil {
		t.Logf("primary group unknown, LogGroup not tested: %v", err)

		return ""
	}

	return group.Name
}

func (e *testEnv) installPHP(version string) {
	e.t.Helper()
	e.write(filepath.Join(e.cfg.PHPFPMDir, "php-fpm"+version), "binary")
}

func (e *testEnv) write(path, content string) {
	e.t.Helper()

	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		e.t.Fatal(err)
	}

	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		e.t.Fatal(err)
	}
}

func (e *testEnv) read(path string) string {
	e.t.Helper()

	content, err := os.ReadFile(path)
	if err != nil {
		e.t.Fatalf("read %s: %v", path, err)
	}

	return string(content)
}

func (e *testEnv) assertMissing(paths ...string) {
	e.t.Helper()

	for _, path := range paths {
		if _, err := os.Lstat(path); !errors.Is(err, fs.ErrNotExist) {
			e.t.Fatalf("%s should not exist (%v)", path, err)
		}
	}
}

func (e *testEnv) nginxVhost(user, domain string) string {
	return filepath.Join(e.cfg.NginxDir, user, domain+".conf")
}

func (e *testEnv) apacheVhost(user, domain string) string {
	return filepath.Join(e.cfg.ApacheDir, user, domain+".conf")
}

func (e *testEnv) poolSection(user, version, domain string) string {
	return filepath.Join(e.cfg.PoolDir, version, user+".d", domain+".conf")
}

func (e *testEnv) poolFile(user, version string) string {
	return filepath.Join(e.cfg.PoolDir, version, user+".conf")
}

func (e *testEnv) unitFile(tag string) string {
	return filepath.Join(e.cfg.UnitDir, "nusa-php"+tag+"@.service")
}

func (e *testEnv) apply(site Site) (string, error) {
	return e.manager.ApplyDomain(context.Background(), site)
}

func (e *testEnv) mustApply(site Site) {
	e.t.Helper()

	if _, err := e.apply(site); err != nil {
		e.t.Fatalf("apply %s failed: %v", site.Domain, err)
	}
}

// fakeRunner records every program run as "<name> <args>" and keeps the
// running state of units the way systemctl would.
type fakeRunner struct {
	mu     sync.Mutex
	calls  []string
	active map[string]bool
	// fail lists command prefixes that exit with code 1.
	fail []string
	// pools records the content of every pool config php-fpm -t checked.
	pools []string
	// base, when set, is shown as $BASE in recorded calls.
	base string
	// certbot, when set, runs instead of every certbot call that is not
	// failed through fail.
	certbot func(args []string) ([]byte, error)
}

func newFakeRunner() *fakeRunner {
	return &fakeRunner{active: map[string]bool{}}
}

func (f *fakeRunner) run(_ context.Context, program string, args ...string) ([]byte, error) {
	f.mu.Lock()
	defer f.mu.Unlock()

	recorded := slices.Clone(args)

	if strings.HasPrefix(filepath.Base(program), "php-fpm") && len(args) == 3 && args[0] == "-t" {
		content, err := os.ReadFile(args[2])
		if err != nil {
			return nil, err
		}

		f.pools = append(f.pools, string(content))
		// The temporary name ends in a random suffix.
		temp, _, _ := strings.Cut(filepath.Base(args[2]), ".tmp-")
		recorded[2] = temp + ".tmp"
	}

	command := strings.Join(append([]string{filepath.Base(program)}, recorded...), " ")
	if f.base != "" {
		command = strings.ReplaceAll(command, f.base, "$BASE")
	}

	f.calls = append(f.calls, command)

	for _, prefix := range f.fail {
		if strings.HasPrefix(command, prefix) {
			return []byte("syntax error"), &runner.Error{Program: program, ExitCode: 1, Output: "syntax error", Err: errors.New("exit status 1")}
		}
	}

	if filepath.Base(program) == "certbot" && f.certbot != nil {
		return f.certbot(args)
	}

	if filepath.Base(program) != "systemctl" {
		return nil, nil
	}

	unit := args[len(args)-1]
	action := strings.Join(args[:len(args)-1], " ")

	switch action {
	case "is-active --quiet":
		if !f.active[unit] {
			return nil, &runner.Error{Program: program, ExitCode: 3, Err: errors.New("exit status 3")}
		}
	case "start", "restart", "enable --now":
		f.active[unit] = true
	case "stop", "disable --now":
		f.active[unit] = false
	}

	return nil, nil
}

func (f *fakeRunner) reset() {
	f.mu.Lock()
	defer f.mu.Unlock()

	f.calls, f.pools = nil, nil
}

func (e *testEnv) assertCalls(want ...string) {
	e.t.Helper()

	if !slices.Equal(e.runner.calls, want) {
		e.t.Fatalf("calls =\n  %s\nwant\n  %s", strings.Join(e.runner.calls, "\n  "), strings.Join(want, "\n  "))
	}
}

func assertContains(t *testing.T, name, content string, wants ...string) {
	t.Helper()

	for _, want := range wants {
		if !strings.Contains(content, want) {
			t.Errorf("%s does not contain %q:\n%s", name, want, content)
		}
	}
}

func assertNotContains(t *testing.T, name, content string, unwanted ...string) {
	t.Helper()

	for _, value := range unwanted {
		if strings.Contains(content, value) {
			t.Errorf("%s contains %q:\n%s", name, value, content)
		}
	}
}

var reloadBoth = []string{
	"nginx -t",
	"apache2ctl configtest",
	"systemctl reload nginx",
	"systemctl reload apache2",
}

func TestApplyDomainSetsUpEverything(t *testing.T) {
	env := newEnv(t)

	root, err := env.apply(budiSite)
	if err != nil {
		t.Fatalf("apply failed: %v", err)
	}

	if root != "/home/budi/domains/example.com/public_html" {
		t.Fatalf("document root = %q", root)
	}

	env.assertCalls(append([]string{
		"systemctl daemon-reload",
		"php-fpm8.4 -t --fpm-config .budi.conf.tmp",
		"systemctl is-active --quiet nusa-php84@budi.service",
		"systemctl enable --now nusa-php84@budi.service",
	}, reloadBoth...)...)

	if len(env.sites) != 1 {
		t.Fatalf("PrepareSite called %d times", len(env.sites))
	}

	site := env.sites[0]
	if site.Home != "/home/budi" || site.UID != 1001 || site.GID != 1001 ||
		!slices.Equal(site.Dirs, []sysuser.Dir{{Name: "domains", Mode: 0o711}, {Name: "example.com", Mode: 0o711}, {Name: "public_html", Mode: 0o755}}) {
		t.Fatalf("unexpected site folders %+v", site)
	}

	assertContains(t, "index.html", string(site.Index), "<title>example.com</title>", "public_html")

	// PHP-FPM checked what it loads: the main configuration with the
	// website's section in place of the include.
	pool := env.read(env.poolFile("budi", "8.4"))
	section := env.read(env.poolSection("budi", "8.4", "example.com"))
	include := "\n\ninclude = " + env.cfg.PoolDir + "/8.4/budi.d/*.conf\n"

	if len(env.runner.pools) != 1 || env.runner.pools[0] != strings.Replace(pool, include, "\n", 1)+section {
		t.Fatalf("php-fpm -t did not check the installed pool configuration:\n%v", env.runner.pools)
	}

	for _, path := range []string{env.unitFile("84"), env.poolFile("budi", "8.4"), env.poolSection("budi", "8.4", "example.com"), env.nginxVhost("budi", "example.com"), env.apacheVhost("budi", "example.com")} {
		info, err := os.Stat(path)
		if err != nil || info.Mode().Perm() != 0o644 {
			t.Fatalf("%s: %v %v, want mode 0644", path, info, err)
		}
	}

	for dir, mode := range map[string]fs.FileMode{
		filepath.Join(env.cfg.NginxDir, "budi"):  0o755,
		filepath.Join(env.cfg.ApacheDir, "budi"): 0o755,
		filepath.Join(env.cfg.PoolDir, "8.4"):    0o755,
		env.cfg.LogDir:                           0o750,
	} {
		info, err := os.Stat(dir)
		if err != nil || !info.IsDir() || info.Mode().Perm() != mode {
			t.Fatalf("%s: %v %v, want a directory with %v", dir, info, err, mode)
		}
	}

	matches, _ := filepath.Glob(filepath.Join(env.base, "etc/*/*/*/.*tmp*"))
	if len(matches) != 0 {
		t.Fatalf("temporary files left behind: %v", matches)
	}
}

func TestRenderedNginxVhost(t *testing.T) {
	env := newEnv(t)
	env.mustApply(budiSite)

	nginx := env.read(env.nginxVhost("budi", "example.com"))

	assertContains(t, "nginx vhost", nginx,
		"    listen 80;\n    server_name example.com www.example.com;\n",
		"root /home/budi/domains/example.com/public_html;",
		"access_log "+env.cfg.LogDir+"/example.com.access.log;",
		"error_log "+env.cfg.LogDir+"/example.com.error.log;",
		"server_tokens off;",
		"disable_symlinks if_not_owner from=$document_root;",
		"client_max_body_size 256m;",
		"include "+env.cfg.NginxDir+"/budi/suspended*.inc;",
		"location ~ /\\.ht {\n        deny all;\n    }",
		"|webp|avif|svg|ico|bmp|woff|woff2|ttf|otf|eot|mp4|webm|ogg|mp3|wav|pdf|txt|zip|gz|tgz|rar|7z)$ {\n        try_files $uri @apache;\n    }",
		"location / {\n        proxy_pass http://127.0.0.1:8080;",
		"location @apache {\n        proxy_pass http://127.0.0.1:8080;",
		"proxy_set_header Host $host;",
		"proxy_set_header X-Real-IP $remote_addr;",
		"proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;",
		"proxy_set_header X-Forwarded-Proto $scheme;",
		"proxy_http_version 1.1;",
		`proxy_set_header Connection "";`,
		"proxy_read_timeout 300s;",
		"proxy_buffer_size 32k;",
		"proxy_buffers 16 16k;",
		"proxy_busy_buffers_size 64k;\n    }",
	)
	assertNotContains(t, "nginx vhost", nginx, "[::]", "443", "<no value>")

	if strings.Count(nginx, "proxy_pass") != 2 || strings.Count(nginx, "{") != strings.Count(nginx, "}") {
		t.Fatalf("unexpected nginx vhost structure:\n%s", nginx)
	}

	env.write(env.cfg.IPv6File, "00000000000000000000000000000001 01 80 10 80       lo\n")
	env.mustApply(budiSite)

	assertContains(t, "nginx vhost with IPv6", env.read(env.nginxVhost("budi", "example.com")),
		"    listen 80;\n    listen [::]:80;\n    server_name example.com www.example.com;\n")
}

func TestRenderedApacheVhost(t *testing.T) {
	env := newEnv(t)
	env.mustApply(budiSite)

	apache := env.read(env.apacheVhost("budi", "example.com"))

	assertContains(t, "apache vhost", apache,
		"<VirtualHost 127.0.0.1:8080>\n",
		"    ServerName example.com\n    ServerAlias www.example.com\n",
		"    DocumentRoot /home/budi/domains/example.com/public_html\n",
		"ErrorLog "+env.cfg.LogDir+"/example.com.apache-error.log\n",
		"DirectoryIndex index.php index.html index.htm\n",
		"<FilesMatch \"\\.(php|phtml)$\">\n        SetHandler \"proxy:unix:/run/nusa-php/php84-budi-a379a6f6eeaf.sock|fcgi://php84-budi-a379a6f6eeaf\"\n    </FilesMatch>",
		"</VirtualHost>\n",
	)
	assertNotContains(t, "apache vhost", apache, "CustomLog", "<no value>")
}

func TestRenderedPoolAndUnit(t *testing.T) {
	env := newEnv(t)
	env.mustApply(budiSite)

	pool := env.read(env.poolFile("budi", "8.4"))

	if pool != "; PHP 8.4 of account budi, run by nusa-php84@budi.service, with\n"+
		"; a pool per website in "+env.cfg.PoolDir+"/8.4/budi.d/. Written by nusad; changes are overwritten.\n"+
		"[global]\npid = /run/nusa-php/php84-budi.pid\nerror_log = syslog\nsyslog.ident = nusa-php84-budi\ndaemonize = no\n\n"+
		"include = "+env.cfg.PoolDir+"/8.4/budi.d/*.conf\n" {
		t.Fatalf("unexpected main pool configuration:\n%s", pool)
	}

	section := env.read(env.poolSection("budi", "8.4", "example.com"))

	assertContains(t, "pool", section,
		"\n[example.com]\nuser = budi\ngroup = budi\n",
		"listen = /run/nusa-php/php84-budi-a379a6f6eeaf.sock\nlisten.owner = budi\nlisten.group = www-data\nlisten.mode = 0660\n",
		"pm = ondemand\npm.max_children = 10\npm.process_idle_timeout = 30s\npm.max_requests = 500\nrequest_terminate_timeout = 300s\n",
		"chdir = /home/budi\n",
		"env[TMPDIR] = /home/budi/tmp\n",
		"env[PATH] = /usr/local/bin:/usr/bin:/bin\n",
		"security.limit_extensions = .php .phtml\n",
		"php_admin_value[open_basedir] = /home/budi/:/usr/share/php/\n",
		"php_admin_value[upload_tmp_dir] = /home/budi/tmp\n",
		"php_admin_value[session.save_path] = /home/budi/tmp\n",
		"php_admin_value[sys_temp_dir] = /home/budi/tmp\n",
		"php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec\n",
		"php_admin_flag[allow_url_include] = off\n",
		"php_flag[display_errors] = off\n"+
			"php_value[max_execution_time] = 120\n"+
			"php_value[max_input_time] = 120\n"+
			"php_value[max_input_vars] = 3000\n"+
			"php_value[memory_limit] = 256M\n"+
			"php_value[post_max_size] = 64M\n"+
			"php_value[upload_max_filesize] = 64M\n",
	)
	assertNotContains(t, "pool", section, "<no value>", "date.timezone", "[global]")

	unit := env.read(env.unitFile("84"))
	poolDir := env.cfg.PoolDir + "/8.4"

	assertContains(t, "unit", unit,
		"After=network.target\n",
		"ConditionPathExists="+poolDir+"/%i.conf\n",
		"ConditionPathExists=!"+env.cfg.NginxDir+"/%i/suspended.inc\n",
		"Type=notify\n",
		"ExecStart="+env.cfg.PHPFPMDir+"/php-fpm8.4 --nodaemonize --fpm-config "+poolDir+"/%i.conf\n",
		"ExecReload=/bin/kill -USR2 $MAINPID\n",
		"RuntimeDirectory=nusa-php\n",
		"RuntimeDirectoryPreserve=yes\n",
		"Restart=on-failure\n",
		"RestartSec=2\n",
		"[Install]\nWantedBy=multi-user.target\n",
	)
}

func TestApplyDomainIsIdempotent(t *testing.T) {
	env := newEnv(t)
	env.mustApply(budiSite)

	before := map[string]string{}
	for _, path := range []string{env.unitFile("84"), env.poolFile("budi", "8.4"), env.nginxVhost("budi", "example.com"), env.apacheVhost("budi", "example.com")} {
		before[path] = env.read(path)
	}

	env.runner.reset()
	env.mustApply(budiSite)

	// Same files, no pool test or restart; the web servers are still tested
	// and reloaded, so a retry after a failed reload does reload.
	env.assertCalls(append([]string{"systemctl is-active --quiet nusa-php84@budi.service"}, reloadBoth...)...)

	for path, content := range before {
		if env.read(path) != content {
			t.Fatalf("%s changed on the second apply", path)
		}
	}
}

func TestApplyDomainReloadsAChangedPool(t *testing.T) {
	env := newEnv(t)
	env.mustApply(budiSite)

	env.write(env.poolFile("budi", "8.4"), "; edited by hand\n")
	env.runner.reset()
	env.mustApply(budiSite)

	env.assertCalls(append([]string{
		"php-fpm8.4 -t --fpm-config .budi.conf.tmp",
		"systemctl is-active --quiet nusa-php84@budi.service",
		"systemctl reload-or-restart nusa-php84@budi.service",
	}, reloadBoth...)...)
}

func TestApplyDomainRestartsPoolsOnANewUnit(t *testing.T) {
	env := newEnv(t)
	env.mustApply(budiSite)

	env.write(env.unitFile("84"), "# old unit\n")
	env.runner.reset()
	env.mustApply(budiSite)

	env.assertCalls(append([]string{
		"systemctl daemon-reload",
		"systemctl is-active --quiet nusa-php84@budi.service",
		"systemctl restart nusa-php84@budi.service",
	}, reloadBoth...)...)
}

func TestApplyDomainKeepsPHPOfASuspendedAccountStopped(t *testing.T) {
	env := newEnv(t)
	env.write(filepath.Join(env.cfg.NginxDir, "budi/suspended.inc"), "return 503;\n")

	env.mustApply(budiSite)

	env.assertCalls(append([]string{
		"systemctl daemon-reload",
		"php-fpm8.4 -t --fpm-config .budi.conf.tmp",
		"systemctl enable nusa-php84@budi.service",
	}, reloadBoth...)...)
}

func TestApplyDomainRefusesARejectedPool(t *testing.T) {
	env := newEnv(t)
	env.runner.fail = []string{"php-fpm8.4 -t"}

	_, err := env.apply(budiSite)
	if !errors.Is(err, ErrPoolRejected) {
		t.Fatalf("got %v, want ErrPoolRejected", err)
	}

	env.assertCalls("systemctl daemon-reload", "php-fpm8.4 -t --fpm-config .budi.conf.tmp")
	env.assertMissing(env.poolFile("budi", "8.4"), env.nginxVhost("budi", "example.com"))

	entries, _ := os.ReadDir(filepath.Join(env.cfg.PoolDir, "8.4"))
	if len(entries) != 0 {
		t.Fatalf("files left in the pool directory: %v", entries)
	}

	assertContains(t, "agent log", env.logs.String(), "PHP-FPM rejected a pool configuration", "syntax error")
}

func TestApplyDomainRollsBackARejectedNewVhost(t *testing.T) {
	for _, failing := range []string{"nginx -t", "apache2ctl configtest"} {
		env := newEnv(t)
		env.runner.fail = []string{failing}

		_, err := env.apply(budiSite)
		if !errors.Is(err, ErrConfigRejected) {
			t.Fatalf("%s failing: got %v, want ErrConfigRejected", failing, err)
		}

		env.assertMissing(env.nginxVhost("budi", "example.com"), env.apacheVhost("budi", "example.com"))

		for _, call := range env.runner.calls {
			if strings.HasPrefix(call, "systemctl reload ") {
				t.Fatalf("%s failing: reloaded anyway: %v", failing, env.runner.calls)
			}
		}

		assertContains(t, "agent log", env.logs.String(), "web server rejected the configuration", "syntax error")
	}
}

func TestApplyDomainRestoresTheVhostsARejectedChangeReplaced(t *testing.T) {
	env := newEnv(t)
	env.mustApply(budiSite)

	nginx := env.read(env.nginxVhost("budi", "example.com"))
	apache := env.read(env.apacheVhost("budi", "example.com"))

	// IPv6 appearing changes the Nginx vhost, which Apache then rejects.
	env.write(env.cfg.IPv6File, "00000000000000000000000000000001 01 80 10 80       lo\n")
	env.runner.fail = []string{"apache2ctl"}
	env.runner.reset()

	if _, err := env.apply(budiSite); !errors.Is(err, ErrConfigRejected) {
		t.Fatalf("got %v, want ErrConfigRejected", err)
	}

	if env.read(env.nginxVhost("budi", "example.com")) != nginx || env.read(env.apacheVhost("budi", "example.com")) != apache {
		t.Fatal("the previous vhosts were not restored")
	}

	env.assertCalls("systemctl is-active --quiet nusa-php84@budi.service", "nginx -t", "apache2ctl configtest")
}

func TestApplyDomainReportsFolderProblems(t *testing.T) {
	env := newEnv(t)
	env.siteErr = sysuser.ErrInTheWay

	if _, err := env.apply(budiSite); !errors.Is(err, sysuser.ErrInTheWay) {
		t.Fatalf("got %v, want ErrInTheWay", err)
	}

	env.assertCalls()
}

func TestManagerRefusesInvalidNames(t *testing.T) {
	env := newEnv(t)
	ctx := context.Background()

	invalid := []Site{
		{User: "root", Domain: "example.com", PHPVersion: "8.4"},
		{User: "budi", Domain: "example.com;\nlisten 81", PHPVersion: "8.4"},
		{User: "budi", Domain: "../../etc", PHPVersion: "8.4"},
		{User: "budi", Domain: "example.com", PHPVersion: "8.4\n"},
		{User: "budi", Domain: "example.com", PHPVersion: "../8"},
	}

	for _, site := range invalid {
		if _, err := env.manager.ApplyDomain(ctx, site); err == nil {
			t.Errorf("ApplyDomain(%+v) succeeded", site)
		}
	}

	if err := env.manager.RemoveDomain(ctx, "budi", "../x"); err == nil {
		t.Error("RemoveDomain accepted an invalid domain")
	}

	if err := env.manager.RemovePool(ctx, "budi", "8"); err == nil {
		t.Error("RemovePool accepted an invalid version")
	}

	for _, name := range []string{"root", "../budi", "budi/x"} {
		if env.manager.SuspendAccount(ctx, name) == nil || env.manager.UnsuspendAccount(ctx, name) == nil ||
			env.manager.RemoveAccount(ctx, name) == nil {
			t.Errorf("an account method accepted %q", name)
		}
	}

	env.assertCalls()

	if len(env.sites) != 0 {
		t.Fatalf("folders were prepared: %+v", env.sites)
	}
}

func TestRemoveDomainKeepsOtherDomainsAndReloads(t *testing.T) {
	env := newEnv(t)
	env.mustApply(budiSite)
	other := budiSite
	other.Domain = "budi.id"
	env.mustApply(other)

	logs := map[string]bool{
		"example.com.access.log":       true,
		"example.com.apache-error.log": true,
		"example.com.error.log.1.gz":   true,
		"budi.id.access.log":           false,
		"example.com.au.access.log":    false,
	}
	for name := range logs {
		env.write(filepath.Join(env.cfg.LogDir, name), "log\n")
	}

	env.runner.reset()

	if err := env.manager.RemoveDomain(context.Background(), "budi", "example.com"); err != nil {
		t.Fatalf("remove failed: %v", err)
	}

	env.assertCalls(append(slices.Clone(reloadBoth), "systemctl is-active --quiet nusa-php84@budi.service", "systemctl reload-or-restart nusa-php84@budi.service")...)
	env.assertMissing(env.poolSection("budi", "8.4", "example.com"))
	env.read(env.poolSection("budi", "8.4", "budi.id"))
	env.assertMissing(env.nginxVhost("budi", "example.com"), env.apacheVhost("budi", "example.com"))
	env.read(env.nginxVhost("budi", "budi.id"))
	env.read(env.apacheVhost("budi", "budi.id"))
	env.read(env.poolFile("budi", "8.4"))

	for name, removed := range logs {
		_, err := os.Stat(filepath.Join(env.cfg.LogDir, name))
		if removed != errors.Is(err, fs.ErrNotExist) {
			t.Errorf("log %s: removed = %v, want %v", name, !removed, removed)
		}
	}
}

func TestRemoveDomainWithoutConfigSucceeds(t *testing.T) {
	env := newEnv(t)

	if err := env.manager.RemoveDomain(context.Background(), "budi", "example.com"); err != nil {
		t.Fatalf("remove failed: %v", err)
	}

	env.assertCalls(reloadBoth...)
}

func TestRemoveDomainRestoresTheVhostsWhenRejected(t *testing.T) {
	env := newEnv(t)
	env.mustApply(budiSite)
	nginx := env.read(env.nginxVhost("budi", "example.com"))
	env.write(filepath.Join(env.cfg.LogDir, "example.com.access.log"), "log\n")

	env.runner.fail = []string{"nginx -t"}

	err := env.manager.RemoveDomain(context.Background(), "budi", "example.com")
	if !errors.Is(err, ErrConfigRejected) {
		t.Fatalf("got %v, want ErrConfigRejected", err)
	}

	if env.read(env.nginxVhost("budi", "example.com")) != nginx {
		t.Fatal("the Nginx vhost was not restored")
	}

	env.read(env.apacheVhost("budi", "example.com"))
	// The domain still runs, so its logs stay.
	env.read(filepath.Join(env.cfg.LogDir, "example.com.access.log"))
}

func TestRemovePoolStopsAndDeletesIt(t *testing.T) {
	env := newEnv(t)
	env.mustApply(budiSite)

	if err := env.manager.RemoveDomain(context.Background(), "budi", "example.com"); err != nil {
		t.Fatal(err)
	}

	env.runner.reset()

	if err := env.manager.RemovePool(context.Background(), "budi", "8.4"); err != nil {
		t.Fatalf("remove pool failed: %v", err)
	}

	env.assertCalls("systemctl disable --now nusa-php84@budi.service")
	env.assertMissing(env.poolFile("budi", "8.4"))
	env.read(env.unitFile("84"))
}

func TestRemovePoolOfAMissingPoolSucceeds(t *testing.T) {
	env := newEnv(t)

	if err := env.manager.RemovePool(context.Background(), "budi", "8.4"); err != nil {
		t.Fatalf("remove pool failed: %v", err)
	}

	env.assertCalls()
}

func TestRemovePoolKeepsAPoolAVhostStillUses(t *testing.T) {
	env := newEnv(t)
	env.installPHP("8.3")

	// example.com moved to 8.3 and a new domain uses 8.4 again before the
	// panel's delayed php.remove_pool for 8.4 arrives.
	env.mustApply(Site{User: "budi", UID: 1001, GID: 1001, Domain: "example.com", PHPVersion: "8.3"})
	env.mustApply(Site{User: "budi", UID: 1001, GID: 1001, Domain: "budi.id", PHPVersion: "8.4"})
	env.runner.reset()

	if err := env.manager.RemovePool(context.Background(), "budi", "8.4"); err != nil {
		t.Fatalf("remove pool failed: %v", err)
	}

	env.assertCalls()
	env.read(env.poolFile("budi", "8.4"))
	assertContains(t, "agent log", env.logs.String(), "PHP-FPM pool still in use", "budi.id")

	// Another account's vhost using its own 8.4 pool does not count.
	sari := Site{User: "sari", UID: 1002, GID: 1002, Domain: "sari.id", PHPVersion: "8.4"}
	env.mustApply(sari)

	if err := env.manager.RemoveDomain(context.Background(), "budi", "budi.id"); err != nil {
		t.Fatal(err)
	}

	env.runner.reset()

	if err := env.manager.RemovePool(context.Background(), "budi", "8.4"); err != nil {
		t.Fatalf("remove pool failed: %v", err)
	}

	env.assertCalls("systemctl disable --now nusa-php84@budi.service")
	env.assertMissing(env.poolFile("budi", "8.4"))
	env.read(env.poolFile("budi", "8.3"))
	env.read(env.poolFile("sari", "8.4"))
}

func TestSuspendAccountServesTheSuspendedPageAndStopsPHP(t *testing.T) {
	env := newEnv(t)
	env.installPHP("8.3")
	env.mustApply(budiSite)
	env.mustApply(Site{User: "budi", UID: 1001, GID: 1001, Domain: "budi.id", PHPVersion: "8.3"})
	env.runner.reset()

	if err := env.manager.SuspendAccount(context.Background(), "budi"); err != nil {
		t.Fatalf("suspend failed: %v", err)
	}

	env.assertCalls(
		"nginx -t",
		"systemctl reload nginx",
		"systemctl stop nusa-php83@budi.service",
		"systemctl stop nusa-php84@budi.service",
	)

	page := env.read(filepath.Join(env.cfg.NginxDir, "budi/suspended.inc"))
	assertContains(t, "suspended.inc", page, "default_type text/html;\n", "return 503 '<!doctype html>", "temporarily unavailable", "</html>';\n")
	assertNotContains(t, "suspended.inc", page, "$")

	// The vhosts include it through a glob.
	matches, err := filepath.Glob(filepath.Join(env.cfg.NginxDir, "budi/suspended*.inc"))
	if err != nil || len(matches) != 1 {
		t.Fatalf("the vhost include glob matches %v (%v)", matches, err)
	}

	// Suspending again is fine.
	if err := env.manager.SuspendAccount(context.Background(), "budi"); err != nil {
		t.Fatalf("second suspend failed: %v", err)
	}
}

func TestSuspendAccountWithoutWebsitesSucceeds(t *testing.T) {
	env := newEnv(t)

	if err := env.manager.SuspendAccount(context.Background(), "budi"); err != nil {
		t.Fatalf("suspend failed: %v", err)
	}

	env.assertCalls("nginx -t", "systemctl reload nginx")
}

func TestSuspendAccountRollsBackWhenNginxRejectsIt(t *testing.T) {
	env := newEnv(t)
	env.mustApply(budiSite)
	env.runner.fail = []string{"nginx -t"}
	env.runner.reset()

	err := env.manager.SuspendAccount(context.Background(), "budi")
	if !errors.Is(err, ErrConfigRejected) {
		t.Fatalf("got %v, want ErrConfigRejected", err)
	}

	env.assertMissing(filepath.Join(env.cfg.NginxDir, "budi/suspended.inc"))
	env.assertCalls("nginx -t")
}

func TestUnsuspendAccountRemovesThePageAndStartsPHP(t *testing.T) {
	env := newEnv(t)
	env.mustApply(budiSite)

	if err := env.manager.SuspendAccount(context.Background(), "budi"); err != nil {
		t.Fatal(err)
	}

	// A pool removed meanwhile is not started.
	env.installPHP("8.3")
	env.write(env.unitFile("83"), "# unit\n")
	env.runner.reset()

	if err := env.manager.UnsuspendAccount(context.Background(), "budi"); err != nil {
		t.Fatalf("unsuspend failed: %v", err)
	}

	env.assertCalls("nginx -t", "systemctl reload nginx", "systemctl start nusa-php84@budi.service")
	env.assertMissing(filepath.Join(env.cfg.NginxDir, "budi/suspended.inc"))

	// A domain applied after unsuspending starts PHP normally again.
	env.runner.reset()
	env.mustApply(budiSite)
	env.assertCalls(append([]string{"systemctl is-active --quiet nusa-php84@budi.service"}, reloadBoth...)...)
}

func TestRemoveAccountRemovesItsWebsitesPoolsAndLogs(t *testing.T) {
	env := newEnv(t)
	env.installPHP("8.3")
	env.mustApply(budiSite)
	env.mustApply(Site{User: "budi", UID: 1001, GID: 1001, Domain: "budi.id", PHPVersion: "8.3"})
	env.mustApply(Site{User: "sari", UID: 1002, GID: 1002, Domain: "example.com.au", PHPVersion: "8.4"})

	if err := env.manager.SuspendAccount(context.Background(), "budi"); err != nil {
		t.Fatal(err)
	}

	logs := map[string]bool{
		"example.com.access.log":          true,
		"example.com.error.log":           true,
		"example.com.apache-error.log":    true,
		"example.com.access.log.1":        true,
		"example.com.access.log.2.gz":     true,
		"budi.id.error.log":               true,
		"example.com.au.access.log":       false,
		"example.com.au.apache-error.log": false,
		"example.com.access.log.backup":   false,
		"other.id.access.log":             false,
	}
	for name := range logs {
		env.write(filepath.Join(env.cfg.LogDir, name), "log\n")
	}

	env.runner.reset()

	if err := env.manager.RemoveAccount(context.Background(), "budi"); err != nil {
		t.Fatalf("remove account failed: %v", err)
	}

	env.assertCalls(append([]string{
		"systemctl disable --now nusa-php83@budi.service",
		"systemctl disable --now nusa-php84@budi.service",
	}, reloadBoth...)...)

	env.assertMissing(
		filepath.Join(env.cfg.NginxDir, "budi"),
		filepath.Join(env.cfg.ApacheDir, "budi"),
		env.poolFile("budi", "8.4"),
		env.poolFile("budi", "8.3"),
	)

	for name, removed := range logs {
		_, err := os.Stat(filepath.Join(env.cfg.LogDir, name))
		if removed != errors.Is(err, fs.ErrNotExist) {
			t.Errorf("log %s: removed = %v, want %v", name, !removed, removed)
		}
	}

	env.read(env.nginxVhost("sari", "example.com.au"))
	env.read(env.apacheVhost("sari", "example.com.au"))
	env.read(env.poolFile("sari", "8.4"))
	env.read(env.unitFile("84"))

	// Repeating it, as a retried account.delete does, succeeds.
	if err := env.manager.RemoveAccount(context.Background(), "budi"); err != nil {
		t.Fatalf("second remove failed: %v", err)
	}
}

func TestRemoveAccountWithoutWebsitesSucceeds(t *testing.T) {
	env := newEnv(t)

	if err := env.manager.RemoveAccount(context.Background(), "budi"); err != nil {
		t.Fatalf("remove account failed: %v", err)
	}

	env.assertCalls(reloadBoth...)
}

func TestRemoveAccountRestoresTheVhostsWhenRejected(t *testing.T) {
	env := newEnv(t)
	env.mustApply(budiSite)
	env.write(filepath.Join(env.cfg.LogDir, "example.com.access.log"), "log\n")
	env.runner.fail = []string{"apache2ctl"}

	err := env.manager.RemoveAccount(context.Background(), "budi")
	if !errors.Is(err, ErrConfigRejected) {
		t.Fatalf("got %v, want ErrConfigRejected", err)
	}

	env.read(env.nginxVhost("budi", "example.com"))
	env.read(env.apacheVhost("budi", "example.com"))
	env.read(filepath.Join(env.cfg.LogDir, "example.com.access.log"))
}

func TestUsedByOtherAccount(t *testing.T) {
	env := newEnv(t)

	used, err := env.manager.UsedByOtherAccount("example.com", "budi")
	if err != nil || used {
		t.Fatalf("empty server: used = %v, %v", used, err)
	}

	env.write(env.nginxVhost("budi", "example.com"), "")
	env.write(env.nginxVhost("sari", "www.toko.id"), "")
	env.write(env.nginxVhost("sari", "blog.id"), "")
	env.write(filepath.Join(env.cfg.NginxDir, "stray.conf"), "")

	tests := []struct {
		domain, user string
		used         bool
	}{
		{"example.com", "budi", false},
		{"example.com", "sari", true},
		{"www.example.com", "sari", true},
		{"toko.id", "budi", true},
		{"www.toko.id", "budi", true},
		{"toko.id", "sari", false},
		{"blog.id", "budi", true},
		{"www.blog.id", "budi", true},
		{"news.blog.id", "budi", false},
		{"other.id", "budi", false},
	}

	for _, test := range tests {
		used, err := env.manager.UsedByOtherAccount(test.domain, test.user)
		if err != nil || used != test.used {
			t.Errorf("UsedByOtherAccount(%s, %s) = %v, %v; want %v", test.domain, test.user, used, err, test.used)
		}
	}
}

func TestPHPInstalled(t *testing.T) {
	env := newEnv(t)

	if !env.manager.PHPInstalled("8.4") {
		t.Fatal("PHP 8.4 is installed")
	}

	if env.manager.PHPInstalled("8.3") {
		t.Fatal("PHP 8.3 is not installed")
	}

	if err := os.Mkdir(filepath.Join(env.cfg.PHPFPMDir, "php-fpm8.2"), 0o755); err != nil {
		t.Fatal(err)
	}

	if env.manager.PHPInstalled("8.2") {
		t.Fatal("a directory is not an installed PHP")
	}

	if env.manager.PHPInstalled("../usr/sbin/php-fpm8.4") {
		t.Fatal("an invalid version is never installed")
	}
}

func TestIsHostname(t *testing.T) {
	env := newEnv(t)

	for domain, want := range map[string]bool{"server1.example.net": true, "example.net": false, "www.server1.example.net": false} {
		if got := env.manager.IsHostname(domain); got != want {
			t.Errorf("IsHostname(%s) = %v, want %v", domain, got, want)
		}
	}
}

func TestDefaultConfigUsesTheServerLayout(t *testing.T) {
	cfg := DefaultConfig()
	manager := New(cfg)

	if root := manager.DocumentRoot("budi", "example.com"); root != "/home/budi/domains/example.com/public_html" {
		t.Fatalf("document root = %s", root)
	}

	data := manager.unitData("8.4")
	if data.RuntimeDirectory != "nusa-php" || data.Binary != "/usr/sbin/php-fpm8.4" || data.PoolDir != "/etc/nusapanel/php-fpm/8.4" {
		t.Fatalf("unexpected unit data %+v", data)
	}

	if manager.unitTemplate("8.4") != "/etc/systemd/system/nusa-php84@.service" {
		t.Fatalf("unexpected unit path %s", manager.unitTemplate("8.4"))
	}

	if manager.nginxVhost("budi", "example.com") != "/etc/nginx/nusapanel/budi/example.com.conf" ||
		manager.apacheVhost("budi", "example.com") != "/etc/apache2/nusapanel/budi/example.com.conf" ||
		manager.poolFile("budi", "8.4") != "/etc/nusapanel/php-fpm/8.4/budi.conf" {
		t.Fatal("unexpected config paths")
	}

	// Customer certificates have a folder of their own, so a domain named
	// like the panel's certificate links (panel.crt) cannot touch them.
	if manager.customCertificateFile("panel.crt") != "/etc/nusapanel/ssl/domains/panel.crt/fullchain.pem" {
		t.Fatalf("unexpected custom certificate path %s", manager.customCertificateFile("panel.crt"))
	}

	if cfg.Run == nil || cfg.PrepareSite == nil || cfg.Hostnames == nil {
		t.Fatal("default operations are missing")
	}
}

func TestRotatedLogNames(t *testing.T) {
	domains := []string{"example.com"}

	for name, want := range map[string]bool{
		"example.com.access.log":        true,
		"example.com.access.log.1":      true,
		"example.com.error.log.14.gz":   true,
		"example.com.apache-error.log":  true,
		"example.com.access.log.gz":     false,
		"example.com.access.logx":       false,
		"example.com.au.access.log":     false,
		"www.example.com.access.log":    false,
		"example.com.access.log.1.gz.x": false,
	} {
		if got := isLogOf(name, domains); got != want {
			t.Errorf("isLogOf(%s) = %v, want %v", name, got, want)
		}
	}
}
