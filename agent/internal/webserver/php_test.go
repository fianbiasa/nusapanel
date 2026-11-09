package webserver

import (
	"os"
	"path/filepath"
	"slices"
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

	assertContains(t, "apache vhost", env.read(env.apacheVhost("budi", "example.com")), "php83-budi.sock")

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

	if !slices.Contains(env.runner.calls, "systemctl disable --now nusa-php84@budi.service") {
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

	assertContains(t, "apache vhost", env.read(env.apacheVhost("budi", "example.com")), "php83-budi.sock")
	assertContains(t, "agent log", env.logs.String(), "could not remove unused PHP-FPM pools")

	// The next apply tries again.
	env.runner.fail = nil
	env.mustApply(moved)
	env.assertMissing(env.poolFile("budi", "8.4"))
}
