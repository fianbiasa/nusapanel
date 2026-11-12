package jail

import (
	"errors"
	"io/fs"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
)

// fakeServer is a server root with the files a jail is made from, and a
// mount table the fake mount and unmount keep.
type fakeServer struct {
	t         *testing.T
	server    string
	mountInfo string
	mounts    []Mount
	unmounts  []string
	// keepMounts makes unmount do nothing, like a mount that will not go.
	keepMounts bool
}

func newFakeServer(t *testing.T) *fakeServer {
	t.Helper()

	server := t.TempDir()

	for path, content := range map[string]string{
		"etc/passwd":                      "root:x:0:0::/root:/bin/bash\nsari:x:1001:1001:rayxo:/home/sari:/bin/bash\n",
		"etc/group":                       "root:x:0:\nsari:x:1001:\n",
		"etc/resolv.conf":                 "nameserver 127.0.0.53\n",
		"etc/nsswitch.conf":               "passwd: files systemd\n",
		"etc/ssh/ssh_config":              "Host *\n",
		"etc/ssh/sshd_config":             "secret server settings\n",
		"etc/ssl/openssl.cnf":             "",
		"etc/php/8.3/cli/php.ini":         "",
		"etc/php/8.3/mods-available/x":    "",
		"etc/php/8.3/fpm/pool.d/sari.ini": "[sari]\n",
		"usr/bin/bash":                    "",
		"dev/null":                        "",
		"home/budi/public_html/index.php": "<?php\n",
	} {
		full := filepath.Join(server, path)
		must(t, os.MkdirAll(filepath.Dir(full), 0o755))
		must(t, os.WriteFile(full, []byte(content), 0o644))
	}

	must(t, os.MkdirAll(filepath.Join(server, "dev/pts"), 0o755))
	must(t, os.MkdirAll(filepath.Join(server, "run/mysqld"), 0o755))
	must(t, os.MkdirAll(filepath.Join(server, "var/lib/rayxo"), 0o750))
	must(t, os.Symlink("usr/bin", filepath.Join(server, "bin")))

	fake := &fakeServer{t: t, server: server, mountInfo: filepath.Join(t.TempDir(), "mountinfo")}
	must(t, os.WriteFile(fake.mountInfo, []byte("22 1 8:1 / / rw - ext4 /dev/sda1 rw\n"), 0o644))

	return fake
}

func (f *fakeServer) manager() *Manager {
	return New(Config{
		Root:      filepath.Join(f.server, "var/lib/rayxo/jail"),
		Server:    f.server,
		MountInfo: f.mountInfo,
		Mount: func(m Mount, target string) error {
			f.mounts = append(f.mounts, m)
			f.setMounted(target, true)

			return nil
		},
		Unmount: func(target string) error {
			f.unmounts = append(f.unmounts, target)
			if !f.keepMounts {
				f.setMounted(target, false)
			}

			return nil
		},
	})
}

func (f *fakeServer) setMounted(target string, mounted bool) {
	content, err := os.ReadFile(f.mountInfo)
	must(f.t, err)

	var lines []string

	for line := range strings.Lines(string(content)) {
		if strings.Fields(line)[4] != strings.ReplaceAll(target, " ", `\040`) {
			lines = append(lines, line)
		}
	}

	if mounted {
		lines = append(lines, "90 22 8:1 /x "+strings.ReplaceAll(target, " ", `\040`)+" rw - ext4 /dev/sda1 rw\n")
	}

	must(f.t, os.WriteFile(f.mountInfo, []byte(strings.Join(lines, "")), 0o644))
}

func (f *fakeServer) budi() Account {
	return Account{Name: "budi", UID: 1002, GID: 1002, Home: "/home/budi"}
}

func TestEnableBuildsAJailThatOnlyKnowsTheAccount(t *testing.T) {
	fake := newFakeServer(t)
	jails := fake.manager()

	if err := jails.Enable(fake.budi()); err != nil {
		t.Fatal(err)
	}

	root := jails.Path("budi")

	if got := read(t, root, "etc/passwd"); got != "root:x:0:0:root:/root:/usr/sbin/nologin\nbudi:x:1002:1002::/home/budi:/bin/bash\n" {
		t.Errorf("passwd = %q", got)
	}

	if got := read(t, root, "etc/group"); got != "root:x:0:\nbudi:x:1002:\n" {
		t.Errorf("group = %q", got)
	}

	if got := read(t, root, "etc/nsswitch.conf"); !strings.HasPrefix(got, "passwd: files\n") {
		t.Errorf("nsswitch.conf = %q", got)
	}

	if got := read(t, root, "etc/resolv.conf"); got != "nameserver 127.0.0.53\n" {
		t.Errorf("resolv.conf = %q", got)
	}

	if got := read(t, root, "etc/ssh/ssh_config"); got != "Host *\n" {
		t.Errorf("ssh_config = %q", got)
	}

	if _, err := os.Lstat(filepath.Join(root, "etc/ssh/sshd_config")); !errors.Is(err, fs.ErrNotExist) {
		t.Errorf("sshd_config is in the jail: %v", err)
	}

	if link, _ := os.Readlink(filepath.Join(root, "bin")); link != "usr/bin" {
		t.Errorf("bin links to %q", link)
	}

	for _, dir := range []string{"tmp", "var/tmp"} {
		info, err := os.Lstat(filepath.Join(root, dir))
		if err != nil || info.Mode().Perm() != 0o777 || info.Mode()&fs.ModeSticky == 0 {
			t.Errorf("%s is %v (%v), want a sticky 0777 folder", dir, info.Mode(), err)
		}
	}

	targets := mountTargets(fake.mounts)
	for _, want := range []string{"usr", "etc/ssl", "etc/php/8.3/cli", "etc/php/8.3/mods-available", "dev/null", "dev/ptmx", "dev/pts", "proc", "run/mysqld", "home/budi"} {
		if !slices.Contains(targets, want) {
			t.Errorf("%s is not mounted; mounts: %v", want, targets)
		}
	}

	if slices.ContainsFunc(targets, func(target string) bool { return strings.Contains(target, "fpm") }) {
		t.Errorf("the PHP-FPM pools, which name every account, are mounted: %v", targets)
	}

	usr, home, proc := fake.mounts[0], fake.mounts[len(fake.mounts)-1], fake.mounts[slices.Index(targets, "proc")]
	if !usr.ReadOnly || !usr.NoSuid || usr.Source != filepath.Join(fake.server, "usr") {
		t.Errorf("/usr is mounted as %+v, want read-only without setuid", usr)
	}

	if home.ReadOnly || !home.NoSuid || !home.NoDev || home.Source != "/home/budi" {
		t.Errorf("the home is mounted as %+v, want writable without setuid and devices", home)
	}

	if !proc.Proc {
		t.Errorf("/proc is mounted as %+v", proc)
	}

	if !jails.Exists("budi") || jails.Exists("sari") {
		t.Error("Exists does not tell the jails apart")
	}
}

func TestEnablingAgainOnlyMountsWhatIsMissingAndLeavesTheHomeAlone(t *testing.T) {
	fake := newFakeServer(t)
	jails := fake.manager()

	if err := jails.Enable(fake.budi()); err != nil {
		t.Fatal(err)
	}

	first := len(fake.mounts)
	home := filepath.Join(jails.Path("budi"), "home/budi")

	// The mounted home has the account's own mode, which must stay.
	must(t, os.Chmod(home, 0o710))
	fake.setMounted(filepath.Join(jails.Path("budi"), "proc"), false)

	if err := jails.Enable(fake.budi()); err != nil {
		t.Fatal(err)
	}

	if got := mountTargets(fake.mounts[first:]); !slices.Equal(got, []string{"proc"}) {
		t.Errorf("enabling again mounted %v, want only proc", got)
	}

	if info, _ := os.Stat(home); info.Mode().Perm() != 0o710 {
		t.Errorf("the mounted home became %v", info.Mode().Perm())
	}
}

func TestOnlyHostingAccountsGetAJail(t *testing.T) {
	fake := newFakeServer(t)
	jails := fake.manager()

	for name, account := range map[string]Account{
		"a system user":       {Name: "www-data", UID: 33, GID: 33, Home: "/home/www-data"},
		"a home elsewhere":    {Name: "budi", UID: 1002, GID: 1002, Home: "/"},
		"a name with a slash": {Name: "../etc", UID: 1002, GID: 1002, Home: "/home/../etc"},
	} {
		if err := jails.Enable(account); err == nil {
			t.Errorf("%s got a jail", name)
		}
	}

	if len(fake.mounts) != 0 {
		t.Errorf("mounted %v", mountTargets(fake.mounts))
	}
}

func TestDisableUnmountsEverythingAndRemovesTheJail(t *testing.T) {
	fake := newFakeServer(t)
	jails := fake.manager()

	if err := jails.Enable(fake.budi()); err != nil {
		t.Fatal(err)
	}

	// Something mounted inside the home has to go before the home.
	nested, home := filepath.Join(jails.Path("budi"), "home/budi/backup"), filepath.Join(jails.Path("budi"), "home/budi")
	fake.setMounted(nested, true)

	if err := jails.Disable("budi"); err != nil {
		t.Fatal(err)
	}

	if len(fake.unmounts) != len(fake.mounts)+1 {
		t.Errorf("unmounted %d of %d mounts", len(fake.unmounts), len(fake.mounts)+1)
	}

	if slices.Index(fake.unmounts, nested) > slices.Index(fake.unmounts, home) {
		t.Errorf("the home was unmounted before what is mounted in it: %v", fake.unmounts)
	}

	if jails.Exists("budi") {
		t.Error("the jail is still there")
	}

	if _, err := os.Stat(filepath.Join(fake.server, "home/budi/public_html/index.php")); err != nil {
		t.Errorf("the account's own files are gone: %v", err)
	}

	if err := jails.Disable("budi"); err != nil {
		t.Errorf("disabling a jail that is gone: %v", err)
	}
}

func TestAJailIsKeptWhileAnythingIsLeftInIt(t *testing.T) {
	fake := newFakeServer(t)
	jails := fake.manager()

	if err := jails.Enable(fake.budi()); err != nil {
		t.Fatal(err)
	}

	fake.keepMounts = true

	if err := jails.Disable("budi"); err == nil || !strings.Contains(err.Error(), "still has mounts") {
		t.Errorf("Disable with a mount left = %v", err)
	}

	// Unmounted, yet the home's place has files: something was wrong.
	fake.keepMounts = false
	must(t, os.WriteFile(filepath.Join(jails.Path("budi"), "home/budi/index.php"), nil, 0o644))

	if err := jails.Disable("budi"); err == nil || !strings.Contains(err.Error(), "not empty") {
		t.Errorf("Disable with files in the home's place = %v", err)
	}

	if !jails.Exists("budi") {
		t.Error("the jail was removed")
	}
}

func TestRestoreMountsTheJailsOfAccountsAndRemovesTheRest(t *testing.T) {
	fake := newFakeServer(t)
	jails := fake.manager()

	gone := Account{Name: "gone", UID: 1003, GID: 1003, Home: "/home/gone"}
	must(t, os.MkdirAll(filepath.Join(fake.server, "home/gone"), 0o755))

	for _, account := range []Account{fake.budi(), gone} {
		if err := jails.Enable(account); err != nil {
			t.Fatal(err)
		}
	}

	// A reboot: nothing is mounted any more.
	must(t, os.WriteFile(fake.mountInfo, []byte("22 1 8:1 / / rw - ext4 /dev/sda1 rw\n"), 0o644))
	fake.mounts = nil

	err := jails.Restore(func(name string) (Account, bool, error) {
		if name == "budi" {
			return fake.budi(), true, nil
		}

		return Account{}, false, nil
	})
	if err != nil {
		t.Fatal(err)
	}

	if !slices.Contains(mountTargets(fake.mounts), "home/budi") {
		t.Errorf("the jail of budi was not mounted again: %v", mountTargets(fake.mounts))
	}

	if slices.Contains(mountTargets(fake.mounts), "home/gone") || jails.Exists("gone") {
		t.Error("the jail of a deleted account is still there")
	}
}

func TestUnescapeMountInfoPaths(t *testing.T) {
	if got := unescape(`/var/lib/a\040b\134c`); got != `/var/lib/a b\c` {
		t.Errorf("unescape = %q", got)
	}
}

func mountTargets(mounts []Mount) []string {
	targets := make([]string, 0, len(mounts))
	for _, m := range mounts {
		targets = append(targets, m.Target)
	}

	return targets
}

func read(t *testing.T, root, path string) string {
	t.Helper()

	content, err := os.ReadFile(filepath.Join(root, path))
	must(t, err)

	return string(content)
}

func must(t *testing.T, err error) {
	t.Helper()

	if err != nil {
		t.Fatal(err)
	}
}
