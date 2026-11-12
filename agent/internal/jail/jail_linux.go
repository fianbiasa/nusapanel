//go:build linux

package jail

import (
	"fmt"
	"os"
	"strconv"
	"strings"
	"time"

	"golang.org/x/sys/unix"
)

// DefaultConfig is the configuration of a RAYXO server.
func DefaultConfig() Config {
	return Config{
		Root:       "/var/lib/rayxo/jail",
		Server:     "/",
		MountInfo:  "/proc/self/mountinfo",
		Mount:      mount,
		Unmount:    unmount,
		Chown:      os.Lchown,
		KillInside: killInside,
	}
}

// mount binds m.Source on target and then sets its flags, which a bind
// mount only takes on a remount; /proc is mounted on its own, showing only
// the processes of the user looking (hidepid=2).
func mount(m Mount, target string) error {
	if m.Proc {
		if err := unix.Mount("proc", target, "proc", unix.MS_NOSUID|unix.MS_NODEV|unix.MS_NOEXEC, "hidepid=2"); err != nil {
			return fmt.Errorf("mount proc: %w", err)
		}

		return nil
	}

	if err := unix.Mount(m.Source, target, "", unix.MS_BIND, ""); err != nil {
		return fmt.Errorf("bind %s: %w", m.Source, err)
	}

	var flags uintptr

	for _, flag := range []struct {
		set   bool
		value uintptr
	}{{m.ReadOnly, unix.MS_RDONLY}, {m.NoSuid, unix.MS_NOSUID}, {m.NoDev, unix.MS_NODEV}, {m.NoExec, unix.MS_NOEXEC}} {
		if flag.set {
			flags |= flag.value
		}
	}

	if flags == 0 {
		return nil
	}

	if err := unix.Mount("", target, "", unix.MS_BIND|unix.MS_REMOUNT|flags, ""); err != nil {
		_ = unix.Unmount(target, unix.MNT_DETACH)

		return fmt.Errorf("set the flags of %s: %w", target, err)
	}

	return nil
}

// unmount detaches target at once, also while a process still uses it.
func unmount(target string) error {
	return unix.Unmount(target, unix.MNT_DETACH|unix.UMOUNT_NOFOLLOW)
}

// killInside kills every process whose root folder is root or below it,
// and repeats while processes are left, as a shell may fork meanwhile.
func killInside(root string) error {
	for range 20 {
		entries, err := os.ReadDir("/proc")
		if err != nil {
			return err
		}

		found := false

		for _, entry := range entries {
			pid, err := strconv.Atoi(entry.Name())
			if err != nil || pid <= 1 {
				continue
			}

			link, err := os.Readlink("/proc/" + entry.Name() + "/root")
			if err != nil || (link != root && !strings.HasPrefix(link, root+"/")) {
				continue
			}

			found = true
			_ = unix.Kill(pid, unix.SIGKILL)
		}

		if !found {
			return nil
		}

		time.Sleep(100 * time.Millisecond)
	}

	return fmt.Errorf("processes are still running in %s", root)
}
