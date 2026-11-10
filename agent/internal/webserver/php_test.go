package webserver

import (
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
)

func TestPHPVersionsListsTheInstalledVersionsOldestFirst(t *testing.T) {
	env := newEnv(t)
	env.installPHP("8.3")
	env.installPHP("7.4")
	env.write(filepath.Join(env.cfg.PHPFPMDir, "php-fpm"), "not a version")
	env.write(filepath.Join(env.cfg.PHPFPMDir, "php-fpm8.4.conf"), "not a binary")

	if err := os.Mkdir(filepath.Join(env.cfg.PHPFPMDir, "php-fpm8.2"), 0o755); err != nil {
		t.Fatal(err)
	}

	versions, err := env.manager.PHPVersions()
	if err != nil {
		t.Fatal(err)
	}

	if !slices.Equal(versions, []string{"7.4", "8.3", "8.4"}) {
		t.Fatalf("versions = %v", versions)
	}
}

func TestPHPVersionsWithoutAnyPHPIsEmpty(t *testing.T) {
	env := newEnv(t)
	env.cfg.PHPFPMDir = filepath.Join(env.base, "missing")
	env.manager = New(env.cfg)

	versions, err := env.manager.PHPVersions()
	if err != nil || versions == nil || len(versions) != 0 {
		t.Fatalf("versions = %#v, %v", versions, err)
	}
}

func TestMovingWebsitesToAnotherPHPRemovesThePoolNothingUses(t *testing.T) {
	env := newEnv(t)
	env.installPHP("8.3")

	other := budiSite
	other.Domain = "budi.id"

	env.mustApply(budiSite)
	env.mustApply(other)

	// One website moves; the other still needs the 8.4 pool.
	moved := budiSite
	moved.PHPVersion = "8.3"
	env.runner.reset()
	env.mustApply(moved)

	assertContains(t, "apache vhost", env.read(env.apacheVhost("budi", "example.com")), "php83-budi-a379a6f6eeaf.sock")

	if !exists(env.poolFile("budi", "8.4")) || !exists(env.poolFile("budi", "8.3")) {
		t.Fatal("both pools must exist while both versions are used")
	}

	// The last website on 8.4 moves too: its pool goes.
	other.PHPVersion = "8.3"
	env.runner.reset()
	env.mustApply(other)

	env.assertMissing(env.poolFile("budi", "8.4"))

	if !exists(env.poolFile("budi", "8.3")) {
		t.Fatal("the 8.3 pool was removed")
	}

	if !slices.Contains(env.runner.calls, "systemctl disable --now rayxo-php84@budi.service") {
		t.Fatalf("the 8.4 pool was not stopped: %v", env.runner.calls)
	}
}

func TestAFailedPoolCleanupDoesNotFailTheWebsite(t *testing.T) {
	env := newEnv(t)
	env.installPHP("8.3")
	env.mustApply(budiSite)

	moved := budiSite
	moved.PHPVersion = "8.3"
	env.runner.fail = []string{"systemctl disable"}

	if _, err := env.apply(moved); err != nil {
		t.Fatalf("apply failed: %v", err)
	}

	assertContains(t, "apache vhost", env.read(env.apacheVhost("budi", "example.com")), "php83-budi-a379a6f6eeaf.sock")
	assertContains(t, "agent log", env.logs.String(), "could not remove unused PHP-FPM pools")

	// The next apply tries again.
	env.runner.fail = nil
	env.mustApply(moved)
	env.assertMissing(env.poolFile("budi", "8.4"))
}

func TestEveryWebsiteHasAPoolWithItsOwnSettings(t *testing.T) {
	env := newEnv(t)

	shop := budiSite
	shop.PHPSettings = map[string]string{
		"memory_limit":        "512M",
		"upload_max_filesize": "512M",
		"post_max_size":       "600M",
		"max_input_vars":      "10000",
		"display_errors":      "on",
		"date.timezone":       "Asia/Jakarta",
	}
	env.mustApply(shop)

	blog := budiSite
	blog.Domain = "budi.id"
	env.mustApply(blog)

	assertContains(t, "shop pool", env.read(env.poolSection("budi", "8.4", "example.com")),
		"[example.com]\n",
		"php_value[date.timezone] = Asia/Jakarta\n",
		"php_flag[display_errors] = on\n",
		"php_value[max_input_vars] = 10000\n",
		"php_value[memory_limit] = 512M\n",
		"php_value[post_max_size] = 600M\n",
		"php_value[upload_max_filesize] = 512M\n",
	)

	blogPool := env.read(env.poolSection("budi", "8.4", "budi.id"))
	assertContains(t, "blog pool", blogPool, "[budi.id]\n", "php_value[memory_limit] = 256M\n", "php_flag[display_errors] = off\n")
	assertNotContains(t, "blog pool", blogPool, "512M", "date.timezone")

	// Nginx lets the larger POSTs through; the other website keeps 256 MB.
	assertContains(t, "shop vhost", env.read(env.nginxVhost("budi", "example.com")), "client_max_body_size 600m;")
	assertContains(t, "blog vhost", env.read(env.nginxVhost("budi", "budi.id")), "client_max_body_size 256m;")

	// One master runs both pools.
	if pool := env.read(env.poolFile("budi", "8.4")); strings.Count(pool, "[") != 1 {
		t.Fatalf("the main configuration should only hold [global]:\n%s", pool)
	}
}

func TestSiteValidateRefusesInvalidPHPSettings(t *testing.T) {
	for name, settings := range map[string]map[string]string{
		"unknown setting":         {"allow_url_fopen": "on"},
		"admin setting":           {"open_basedir": "/"},
		"size without unit":       {"memory_limit": "512"},
		"size in gigabytes":       {"memory_limit": "1G"},
		"too much memory":         {"memory_limit": "8192M"},
		"too little memory":       {"memory_limit": "16M"},
		"upload over 1 GiB":       {"upload_max_filesize": "2048M", "post_max_size": "2048M"},
		"post below upload":       {"upload_max_filesize": "128M", "post_max_size": "64M"},
		"no time limit":           {"max_execution_time": "0"},
		"time over 300 s":         {"max_execution_time": "301"},
		"too few input vars":      {"max_input_vars": "999"},
		"flag as yes":             {"display_errors": "yes"},
		"unknown time zone":       {"date.timezone": "Asia/Atlantis"},
		"local time zone":         {"date.timezone": "Local"},
		"config syntax in value":  {"memory_limit": "256M\nphp_admin_value[open_basedir] = /"},
		"config syntax in a zone": {"date.timezone": "Asia/Jakarta;x"},
	} {
		t.Run(name, func(t *testing.T) {
			site := budiSite
			site.PHPSettings = settings

			if err := site.Validate(); err == nil {
				t.Fatal("Validate accepted it")
			}
		})
	}
}

func TestPoolsOfAnAccountFromBeforePoolsPerWebsiteMoveWithoutDowntime(t *testing.T) {
	env := newEnv(t)
	other := budiSite
	other.Domain = "budi.id"
	env.mustApply(budiSite)
	env.mustApply(other)

	// Both websites as an older rayxod left them: one pool for the account,
	// which both Apache vhosts use.
	legacy := strings.Replace(env.read(env.poolSection("budi", "8.4", "example.com")), "[example.com]", "[budi]", 1)
	legacy = strings.ReplaceAll(legacy, "php84-budi-a379a6f6eeaf.sock", "php84-budi.sock")
	env.write(env.poolFile("budi", "8.4"), "[global]\npid = /run/rayxo-php/php84-budi.pid\n"+legacy)

	for _, domain := range []string{"example.com", "budi.id"} {
		vhost := env.read(env.apacheVhost("budi", domain))
		vhost = strings.ReplaceAll(vhost, "php84-budi-"+siteKey(domain), "php84-budi")
		env.write(env.apacheVhost("budi", domain), vhost)
		os.Remove(env.poolSection("budi", "8.4", domain))
	}

	// The first website moves to its own pool; the old pool stays for the
	// other website.
	env.mustApply(budiSite)

	main := env.read(env.poolFile("budi", "8.4"))
	assertContains(t, "main pool", main, "include = ", "\n[budi]\n", "listen = /run/rayxo-php/php84-budi.sock\n")
	assertContains(t, "apache vhost", env.read(env.apacheVhost("budi", "example.com")), "php84-budi-a379a6f6eeaf.sock")

	// The last website moves too: the old pool goes.
	env.runner.reset()
	env.mustApply(other)

	main = env.read(env.poolFile("budi", "8.4"))
	assertNotContains(t, "main pool", main, "[budi]", "php84-budi.sock")
	env.read(env.poolSection("budi", "8.4", "example.com"))
	env.read(env.poolSection("budi", "8.4", "budi.id"))

	if !slices.Contains(env.runner.calls, "systemctl reload-or-restart rayxo-php84@budi.service") {
		t.Fatalf("PHP-FPM was not reloaded without the old pool: %v", env.runner.calls)
	}
}

func TestAWebsiteThatMovesLeavesThePoolOfItsOldVersion(t *testing.T) {
	env := newEnv(t)
	env.installPHP("8.3")

	other := budiSite
	other.Domain = "budi.id"
	env.mustApply(budiSite)
	env.mustApply(other)

	moved := budiSite
	moved.PHPVersion = "8.3"
	env.mustApply(moved)

	env.assertMissing(env.poolSection("budi", "8.4", "example.com"))
	env.read(env.poolSection("budi", "8.4", "budi.id"))
	env.read(env.poolSection("budi", "8.3", "example.com"))
}
