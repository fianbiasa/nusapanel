package webserver

import (
	"context"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"os/user"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
)

// service is a web server whose configuration is tested before it is
// reloaded.
type service struct {
	// unit is the systemd unit to reload.
	unit string
	// test checks the whole configuration of the server.
	test []string
}

var (
	nginxService  = service{unit: "nginx", test: []string{nginxProgram, "-t"}}
	apacheService = service{unit: "apache2", test: []string{apachectlProgram, "configtest"}}
)

// fileChange replaces the file at path with content, or removes it when
// content is nil.
type fileChange struct {
	path    string
	content []byte
}

// savedFile is a file as it was before a fileChange.
type savedFile struct {
	path    string
	content []byte
	existed bool
}

// commit applies changes, tests the configuration of every service and
// reloads them. When a test fails, every file is put back the way it was,
// nothing is reloaded and the error wraps ErrConfigRejected. The tests and
// reloads run even when no file changed, so repeating a call whose reload
// failed reloads again. Callers must hold m.mu.
func (m *Manager) commit(ctx context.Context, changes []fileChange, services ...service) error {
	saved, err := applyChanges(changes)
	if err != nil {
		return errors.Join(err, restoreFiles(saved))
	}

	for _, svc := range services {
		_, err := m.cfg.Run(ctx, svc.test[0], svc.test[1:]...)
		if err == nil {
			continue
		}

		files := make([]string, len(changes))
		for i, change := range changes {
			files[i] = change.path
		}

		m.logger().Error("web server rejected the configuration; changes rolled back",
			"service", svc.unit, "files", files, "error", err)

		if restoreErr := restoreFiles(saved); restoreErr != nil {
			return fmt.Errorf("%s rejected the configuration and restoring the previous files failed: %w",
				svc.unit, errors.Join(err, restoreErr))
		}

		return fmt.Errorf("%w (%s)", ErrConfigRejected, svc.unit)
	}

	for _, svc := range services {
		if err := m.systemctl(ctx, "reload", svc.unit); err != nil {
			return err
		}
	}

	return nil
}

// applyChanges applies changes in order and returns what each file was
// before, including the change that failed, for restoreFiles.
func applyChanges(changes []fileChange) ([]savedFile, error) {
	saved := make([]savedFile, 0, len(changes))

	for _, change := range changes {
		content, err := os.ReadFile(change.path)
		if err != nil && !errors.Is(err, os.ErrNotExist) {
			return saved, fmt.Errorf("read %s: %w", change.path, err)
		}

		saved = append(saved, savedFile{path: change.path, content: content, existed: err == nil})

		if change.content == nil {
			err = removeFile(change.path)
		} else {
			err = writeFileAtomic(change.path, change.content, 0o644, nil)
		}

		if err != nil {
			return saved, err
		}
	}

	return saved, nil
}

// restoreFiles puts saved files back, last change first.
func restoreFiles(saved []savedFile) error {
	var errs []error

	for i := len(saved) - 1; i >= 0; i-- {
		file := saved[i]

		var err error
		if file.existed {
			err = writeFileAtomic(file.path, file.content, 0o644, nil)
		} else {
			err = removeFile(file.path)
		}

		if err != nil {
			errs = append(errs, err)
		}
	}

	return errors.Join(errs...)
}

// writeFileAtomic replaces path with content: it writes a temporary file in
// the same directory and renames it over path, so readers see either the
// old or the new file, never a partial one. check, when set, may refuse the
// temporary file before it replaces path. The temporary name starts with a
// dot and does not end in .conf, so no include glob ever picks it up.
func writeFileAtomic(path string, content []byte, mode fs.FileMode, check func(temp string) error) (err error) {
	temp, err := os.CreateTemp(filepath.Dir(path), "."+filepath.Base(path)+".tmp-*")
	if err != nil {
		return fmt.Errorf("write %s: %w", path, err)
	}

	defer func() {
		if err != nil {
			_ = os.Remove(temp.Name())
		}
	}()

	_, err = temp.Write(content)
	if err == nil {
		err = temp.Chmod(mode)
	}

	if err == nil {
		err = temp.Sync()
	}

	if closeErr := temp.Close(); err == nil {
		err = closeErr
	}

	if err != nil {
		return fmt.Errorf("write %s: %w", path, err)
	}

	if check != nil {
		if err = check(temp.Name()); err != nil {
			return err
		}
	}

	if err = os.Rename(temp.Name(), path); err != nil {
		return fmt.Errorf("write %s: %w", path, err)
	}

	return nil
}

// ensureDir creates dir with mode when it is missing, or sets its mode.
// Only root-owned locations are passed here, never anything inside a home.
func ensureDir(dir string, mode fs.FileMode) error {
	if err := os.MkdirAll(dir, mode); err != nil {
		return fmt.Errorf("create %s: %w", dir, err)
	}

	info, err := os.Lstat(dir)
	if err != nil {
		return fmt.Errorf("check %s: %w", dir, err)
	}

	if !info.IsDir() {
		return fmt.Errorf("%s is not a directory", dir)
	}

	if info.Mode().Perm() != mode {
		if err := os.Chmod(dir, mode); err != nil {
			return fmt.Errorf("chmod %s: %w", dir, err)
		}
	}

	return nil
}

// removeFile removes path; a missing file is not an error.
func removeFile(path string) error {
	if err := os.Remove(path); err != nil && !errors.Is(err, os.ErrNotExist) {
		return fmt.Errorf("remove %s: %w", path, err)
	}

	return nil
}

// exists reports whether something is at path, without following symlinks.
func exists(path string) bool {
	_, err := os.Lstat(path)

	return err == nil
}

// lookupGroup returns the GID of the group name.
func lookupGroup(name string) (int, error) {
	group, err := user.LookupGroup(name)
	if err != nil {
		return 0, fmt.Errorf("look up group %s: %w", name, err)
	}

	gid, err := strconv.Atoi(group.Gid)
	if err != nil {
		return 0, fmt.Errorf("group %s has gid %q: %w", name, group.Gid, err)
	}

	return gid, nil
}

// rotatedSuffix matches what logrotate appends to a rotated log: .1,
// .2.gz and so on.
var rotatedSuffix = regexp.MustCompile(`^(\.[0-9]+(\.gz)?)?$`)

// logKinds are the logs every domain has in LogDir.
var logKinds = []string{"access.log", "error.log", "apache-error.log"}

// removeLogs deletes the logs of domains, rotated ones included. Names are
// matched exactly, so the logs of example.com never take those of
// example.com.au with them.
func (m *Manager) removeLogs(domains []string) error {
	if len(domains) == 0 {
		return nil
	}

	entries, err := os.ReadDir(m.cfg.LogDir)
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}

	if err != nil {
		return fmt.Errorf("list %s: %w", m.cfg.LogDir, err)
	}

	for _, entry := range entries {
		if entry.IsDir() || !isLogOf(entry.Name(), domains) {
			continue
		}

		if err := removeFile(filepath.Join(m.cfg.LogDir, entry.Name())); err != nil {
			return err
		}
	}

	return nil
}

// isLogOf reports whether name is a log, possibly rotated, of one of domains.
func isLogOf(name string, domains []string) bool {
	for _, domain := range domains {
		for _, kind := range logKinds {
			if rest, ok := strings.CutPrefix(name, domain+"."+kind); ok && rotatedSuffix.MatchString(rest) {
				return true
			}
		}
	}

	return false
}
