// Package jail builds the chroot a hosting account with SSH access logs in
// to: sshd's ChrootDirectory, /var/lib/rayxo/jail/<user>. The jail is owned
// by root and holds the server's programs (/usr, read-only and without
// setuid), an /etc of its own with only root and the account in passwd and
// group, the few device nodes a shell needs, a /proc that shows the
// account's own processes only, the MariaDB socket, and the account's home
// at the same path as outside, so paths in its configuration keep working.
// The account cannot see other homes, other accounts' names or most of the
// server's /etc.
//
// Bind mounts do not survive a reboot: Restore mounts every jail again when
// the agent starts.
package jail

import (
	"bufio"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"slices"
	"strconv"
	"strings"

	"rayxo/agent/internal/validate"
)

// Mount is one mount inside a jail.
type Mount struct {
	// Source is the folder or file of the server bound into the jail; it is
	// unused for /proc.
	Source string
	// Target is where it goes, relative to the jail.
	Target   string
	Proc     bool
	File     bool
	ReadOnly bool
	NoSuid   bool
	NoDev    bool
	NoExec   bool
}

// Config holds every location the Manager touches and the operations it
// needs from the system, so tests run without root.
type Config struct {
	// Root holds one jail per account, <Root>/<user>.
	Root string
	// Server is the server's own root, "/"; /etc, /usr, /dev and the
	// MariaDB socket are found under it.
	Server string
	// MountInfo lists the mounts, /proc/self/mountinfo.
	MountInfo string
	// Mount mounts m at target, an absolute path. nil means jails are not
	// supported on this platform.
	Mount func(m Mount, target string) error
	// Unmount detaches the mount at target, also while it is busy.
	Unmount func(target string) error
	// Chown gives path to uid and gid without following a link; nil leaves
	// it to whoever made it (tests).
	Chown func(path string, uid, gid int) error
	// KillInside kills every process whose root is the folder root; nil
	// kills nothing (tests).
	KillInside func(root string) error
}

// Account is whose jail it is.
type Account struct {
	Name string
	UID  int
	GID  int
	Home string
}

// Manager builds and removes jails. Callers make sure only one call works
// on an account at a time (the agent's account lock).
type Manager struct {
	cfg Config
}

// New returns a Manager for cfg. It does not touch the system.
func New(cfg Config) *Manager {
	return &Manager{cfg: cfg}
}

// Folders of the server's /etc a jail sees, read-only: what the shell,
// TLS, the MariaDB client and editors need, and nothing that names other
// accounts. PHP's command line settings are added per version.
var etcFolders = []string{
	"alternatives", "ssl", "ca-certificates", "mysql", "profile.d", "ld.so.conf.d",
	"bash_completion.d", "terminfo", "vim",
}

// Files of the server's /etc copied into a jail: they hold nothing about
// other accounts. Missing ones are skipped.
var etcFiles = []string{
	"bash.bashrc", "profile", "inputrc", "environment", "hosts", "host.conf", "resolv.conf",
	"localtime", "timezone", "os-release", "lsb-release", "mime.types", "ld.so.cache", "ld.so.conf",
	"nanorc", "gitconfig", "wgetrc", "nsswitch.conf", "ssh/ssh_config", "default/locale",
}

// The device nodes of the server a shell needs.
var devices = []string{"null", "zero", "full", "random", "urandom", "tty", "ptmx"}

// A jail's own nsswitch.conf: users and groups from its own files only.
const nsswitch = "passwd: files\ngroup: files\nshadow: files\nhosts: files dns\n"

// Path is the jail of name.
func (m *Manager) Path(name string) string {
	return filepath.Join(m.cfg.Root, name)
}

// Exists reports whether name has a jail.
func (m *Manager) Exists(name string) bool {
	info, err := os.Lstat(m.Path(name))

	return err == nil && info.IsDir()
}

// Enable builds the jail of account and mounts what is not mounted yet.
// Calling it again changes nothing, so it also repairs a jail.
func (m *Manager) Enable(account Account) error {
	if m.cfg.Mount == nil {
		return errors.ErrUnsupported
	}

	if err := validate.Username(account.Name); err != nil {
		return err
	}

	if account.Home != "/home/"+account.Name || account.UID < 1000 || account.GID < 1000 {
		return fmt.Errorf("%s is not a hosting account with its home in /home", account.Name)
	}

	root := m.Path(account.Name)
	mounts := m.mounts(account)

	mounted, err := m.mounted()
	if err != nil {
		return err
	}

	if err := m.skeleton(root, account, mounts, mounted); err != nil {
		return err
	}

	for _, mount := range mounts {
		target := filepath.Join(root, mount.Target)

		if slices.Contains(mounted, target) {
			continue
		}

		if err := m.cfg.Mount(mount, target); err != nil {
			return fmt.Errorf("mount %s in the jail of %s: %w", mount.Target, account.Name, err)
		}
	}

	return nil
}

// Disable ends every process in the jail of name, unmounts everything in
// it and removes it. The jail is only removed once nothing is mounted in it
// any more and the places the home and /usr were mounted are empty, so
// removing it can never reach the account's files or the server's. A jail
// that does not exist succeeds.
func (m *Manager) Disable(name string) error {
	if err := validate.Username(name); err != nil {
		return err
	}

	root := m.Path(name)

	if _, err := os.Lstat(root); errors.Is(err, fs.ErrNotExist) {
		return nil
	}

	if m.cfg.KillInside != nil {
		if err := m.cfg.KillInside(root); err != nil {
			return fmt.Errorf("end the processes in the jail of %s: %w", name, err)
		}
	}

	inside, err := m.mountsUnder(root)
	if err != nil {
		return err
	}

	// The deepest first, so /dev/pts goes before anything above it.
	slices.SortStableFunc(inside, func(a, b string) int { return strings.Count(b, "/") - strings.Count(a, "/") })

	for _, target := range inside {
		if err := m.cfg.Unmount(target); err != nil {
			return fmt.Errorf("unmount %s: %w", target, err)
		}
	}

	left, err := m.mountsUnder(root)
	if err != nil {
		return err
	}

	if len(left) > 0 {
		return fmt.Errorf("the jail of %s still has mounts (%s); it is kept", name, strings.Join(left, ", "))
	}

	for _, mount := range m.mounts(Account{Name: name, Home: "/home/" + name}) {
		if mount.File || mount.Proc {
			continue
		}

		if !emptyOrMissing(filepath.Join(root, mount.Target)) {
			return fmt.Errorf("%s in the jail of %s is not empty; it is kept", mount.Target, name)
		}
	}

	if err := os.RemoveAll(root); err != nil {
		return fmt.Errorf("remove the jail of %s: %w", name, err)
	}

	return nil
}

// Restore mounts every jail again, e.g. after a reboot, and removes the
// jails of accounts that no longer exist. lookup returns the account of a
// name, or false when there is none.
func (m *Manager) Restore(lookup func(name string) (Account, bool, error)) error {
	entries, err := os.ReadDir(m.cfg.Root)
	if errors.Is(err, fs.ErrNotExist) {
		return nil
	}

	if err != nil {
		return fmt.Errorf("list %s: %w", m.cfg.Root, err)
	}

	var errs []error

	for _, entry := range entries {
		name := entry.Name()

		if !entry.IsDir() || validate.Username(name) != nil {
			continue
		}

		account, found, err := lookup(name)

		switch {
		case err != nil:
			errs = append(errs, fmt.Errorf("look up %s: %w", name, err))
		case found:
			errs = append(errs, m.Enable(account))
		default:
			errs = append(errs, m.Disable(name))
		}
	}

	return errors.Join(errs...)
}

// mounts are what the jail of account mounts, in order.
func (m *Manager) mounts(account Account) []Mount {
	etc := m.server("etc")
	list := []Mount{{Source: m.server("usr"), Target: "usr", ReadOnly: true, NoSuid: true, NoDev: true}}

	folders := slices.Clone(etcFolders)

	// PHP's command line settings, not the PHP-FPM pools, which name every
	// account.
	if versions, err := os.ReadDir(filepath.Join(etc, "php")); err == nil {
		for _, version := range versions {
			for _, part := range []string{"cli", "mods-available"} {
				folders = append(folders, filepath.Join("php", version.Name(), part))
			}
		}
	}

	for _, folder := range folders {
		if isDir(filepath.Join(etc, folder)) {
			list = append(list, Mount{Source: filepath.Join(etc, folder), Target: filepath.Join("etc", folder), ReadOnly: true, NoSuid: true, NoDev: true})
		}
	}

	for _, device := range devices {
		list = append(list, Mount{Source: m.server("dev", device), Target: filepath.Join("dev", device), File: true})
	}

	return append(list,
		Mount{Source: m.server("dev", "pts"), Target: "dev/pts", NoSuid: true, NoExec: true},
		Mount{Target: "proc", Proc: true, NoSuid: true, NoDev: true, NoExec: true},
		Mount{Source: m.server("run", "mysqld"), Target: "run/mysqld", ReadOnly: true, NoSuid: true, NoDev: true, NoExec: true},
		Mount{Source: account.Home, Target: strings.TrimPrefix(account.Home, "/"), NoSuid: true, NoDev: true},
	)
}

// skeleton makes the folders, files and links of the jail of account, all
// owned by root, so the account can only write where it may: /tmp,
// /var/tmp and its home. Places something is mounted on already are left
// alone: one of them is the account's home.
func (m *Manager) skeleton(root string, account Account, mounts []Mount, mounted []string) error {
	for _, dir := range []string{m.cfg.Root, root} {
		if err := m.ensureDir(dir, 0o755); err != nil {
			return err
		}
	}

	for _, folder := range []string{"etc", "var", "run", "home"} {
		if err := m.ensurePath(root, folder, 0o755); err != nil {
			return err
		}
	}

	for _, folder := range []string{"tmp", "var/tmp"} {
		if err := m.ensurePath(root, folder, 0o777|fs.ModeSticky); err != nil {
			return err
		}
	}

	for _, mount := range mounts {
		if slices.Contains(mounted, filepath.Join(root, mount.Target)) {
			continue
		}

		if mount.File {
			if err := m.ensurePath(root, filepath.Dir(mount.Target), 0o755); err != nil {
				return err
			}

			if err := m.ensureFile(filepath.Join(root, mount.Target), nil, 0o644); err != nil {
				return err
			}

			continue
		}

		if err := m.ensurePath(root, mount.Target, 0o755); err != nil {
			return err
		}
	}

	// The program folders as on the server: links into /usr.
	for _, name := range []string{"bin", "sbin", "lib", "lib32", "lib64"} {
		if target, err := os.Readlink(m.server(name)); err == nil && strings.HasPrefix(target, "usr/") {
			if err := ensureLink(target, filepath.Join(root, name)); err != nil {
				return err
			}
		}
	}

	links := map[string]string{
		"dev/fd":     "/proc/self/fd",
		"dev/stdin":  "/proc/self/fd/0",
		"dev/stdout": "/proc/self/fd/1",
		"dev/stderr": "/proc/self/fd/2",
	}

	for path, target := range links {
		if err := ensureLink(target, filepath.Join(root, path)); err != nil {
			return err
		}
	}

	if err := m.copyEtc(root); err != nil {
		return err
	}

	uid, gid := strconv.Itoa(account.UID), strconv.Itoa(account.GID)
	passwd := "root:x:0:0:root:/root:/usr/sbin/nologin\n" + account.Name + ":x:" + uid + ":" + gid + "::" + account.Home + ":/bin/bash\n"
	group := "root:x:0:\n" + account.Name + ":x:" + gid + ":\n"

	if err := m.ensureFile(filepath.Join(root, "etc/passwd"), []byte(passwd), 0o644); err != nil {
		return err
	}

	return m.ensureFile(filepath.Join(root, "etc/group"), []byte(group), 0o644)
}

// copyEtc copies the server's harmless /etc files into the jail, so a
// changed resolver or time zone reaches it when the jail is enabled again.
func (m *Manager) copyEtc(root string) error {
	for _, name := range etcFiles {
		content, err := readFile(m.server("etc", name))
		if errors.Is(err, fs.ErrNotExist) {
			continue
		}

		if err != nil {
			return fmt.Errorf("read /etc/%s: %w", name, err)
		}

		if name == "nsswitch.conf" {
			content = []byte(nsswitch)
		}

		if err := m.ensurePath(root, filepath.Join("etc", filepath.Dir(name)), 0o755); err != nil {
			return err
		}

		if err := m.ensureFile(filepath.Join(root, "etc", name), content, 0o644); err != nil {
			return err
		}
	}

	return nil
}

// readFile reads a regular file of the server, following links, up to 8 MB
// (ld.so.cache is the largest).
func readFile(path string) ([]byte, error) {
	file, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer file.Close()

	info, err := file.Stat()
	if err != nil {
		return nil, err
	}

	if !info.Mode().IsRegular() {
		return nil, fmt.Errorf("%s is not a regular file", path)
	}

	return io.ReadAll(io.LimitReader(file, 8<<20))
}

// server is a path on the server itself.
func (m *Manager) server(parts ...string) string {
	return filepath.Join(append([]string{m.cfg.Server}, parts...)...)
}

// ensurePath makes every folder of rel inside root, the last one with mode
// and the ones above it 0755.
func (m *Manager) ensurePath(root, rel string, mode fs.FileMode) error {
	if rel == "." || rel == "" {
		return nil
	}

	parts := strings.Split(filepath.Clean(rel), string(filepath.Separator))
	path := root

	for i, part := range parts {
		if part == ".." {
			return fmt.Errorf("%s leaves the jail", rel)
		}

		path = filepath.Join(path, part)
		folderMode := fs.FileMode(0o755)

		if i == len(parts)-1 {
			folderMode = mode
		}

		if err := m.ensureDir(path, folderMode); err != nil {
			return err
		}
	}

	return nil
}

// ensureDir makes dir with mode, owned by root, or gives an existing one
// that mode and owner. Anything else at dir, a link too, is an error.
func (m *Manager) ensureDir(dir string, mode fs.FileMode) error {
	info, err := os.Lstat(dir)

	switch {
	case errors.Is(err, fs.ErrNotExist):
		if err := os.Mkdir(dir, mode.Perm()); err != nil {
			return fmt.Errorf("create %s: %w", dir, err)
		}
	case err != nil:
		return fmt.Errorf("check %s: %w", dir, err)
	case !info.IsDir():
		return fmt.Errorf("%s is not a folder", dir)
	}

	if err := os.Chmod(dir, mode); err != nil {
		return fmt.Errorf("chmod %s: %w", dir, err)
	}

	return m.chownRoot(dir)
}

// ensureFile writes content to path, owned by root; nil content only makes
// sure an empty file is there for a device to be mounted on.
func (m *Manager) ensureFile(path string, content []byte, mode fs.FileMode) error {
	info, err := os.Lstat(path)

	switch {
	case err == nil && !info.Mode().IsRegular():
		if content == nil && info.Mode()&fs.ModeDevice != 0 {
			// The device mounted on it.
			return nil
		}

		return fmt.Errorf("%s is not a regular file", path)
	case err == nil && content == nil:
		return nil
	case err != nil && !errors.Is(err, fs.ErrNotExist):
		return fmt.Errorf("check %s: %w", path, err)
	}

	if err := os.WriteFile(path, content, mode); err != nil {
		return fmt.Errorf("write %s: %w", path, err)
	}

	if err := os.Chmod(path, mode); err != nil {
		return fmt.Errorf("chmod %s: %w", path, err)
	}

	return m.chownRoot(path)
}

func (m *Manager) chownRoot(path string) error {
	if m.cfg.Chown == nil {
		return nil
	}

	if err := m.cfg.Chown(path, 0, 0); err != nil {
		return fmt.Errorf("chown %s: %w", path, err)
	}

	return nil
}

// ensureLink makes path a symbolic link to target.
func ensureLink(target, path string) error {
	if current, err := os.Readlink(path); err == nil && current == target {
		return nil
	}

	if err := os.Remove(path); err != nil && !errors.Is(err, fs.ErrNotExist) {
		return fmt.Errorf("replace %s: %w", path, err)
	}

	if err := os.Symlink(target, path); err != nil {
		return fmt.Errorf("link %s: %w", path, err)
	}

	return nil
}

func isDir(path string) bool {
	info, err := os.Stat(path)

	return err == nil && info.IsDir()
}

// emptyOrMissing reports whether the folder path has nothing in it.
func emptyOrMissing(path string) bool {
	dir, err := os.Open(path)
	if errors.Is(err, fs.ErrNotExist) {
		return true
	}

	if err != nil {
		return false
	}
	defer dir.Close()

	names, err := dir.Readdirnames(1)

	return len(names) == 0 && errors.Is(err, io.EOF)
}

// mounted lists the mount points of the system.
func (m *Manager) mounted() ([]string, error) {
	file, err := os.Open(m.cfg.MountInfo)
	if err != nil {
		return nil, fmt.Errorf("read the mounts: %w", err)
	}
	defer file.Close()

	var points []string

	scanner := bufio.NewScanner(file)
	scanner.Buffer(make([]byte, 0, 64*1024), 1024*1024)

	for scanner.Scan() {
		fields := strings.Fields(scanner.Text())
		if len(fields) >= 5 {
			points = append(points, unescape(fields[4]))
		}
	}

	if err := scanner.Err(); err != nil {
		return nil, fmt.Errorf("read the mounts: %w", err)
	}

	return points, nil
}

// mountsUnder lists the mount points inside root.
func (m *Manager) mountsUnder(root string) ([]string, error) {
	points, err := m.mounted()
	if err != nil {
		return nil, err
	}

	var inside []string

	for _, point := range points {
		if strings.HasPrefix(point, root+"/") && !slices.Contains(inside, point) {
			inside = append(inside, point)
		}
	}

	return inside, nil
}

// unescape undoes the octal escapes of a mountinfo path (\040 for a space).
func unescape(path string) string {
	if !strings.Contains(path, `\`) {
		return path
	}

	var out strings.Builder

	for i := 0; i < len(path); i++ {
		if path[i] == '\\' && i+3 < len(path) {
			if value, err := strconv.ParseUint(path[i+1:i+4], 8, 8); err == nil {
				out.WriteByte(byte(value))
				i += 3

				continue
			}
		}

		out.WriteByte(path[i])
	}

	return out.String()
}
