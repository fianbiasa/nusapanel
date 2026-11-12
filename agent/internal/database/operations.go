package database

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"regexp"
	"slices"
	"strings"

	"rayxo/agent/internal/validate"
)

const (
	dumpProgram  = "/usr/bin/mariadb-dump"
	checkProgram = "/usr/bin/mariadb-check"
	gzipProgram  = "/usr/bin/gzip"
	socketPath   = "/run/mysqld/mysqld.sock"
	// maxMessage is the most of MariaDB's own words a failure carries.
	maxMessage = 500
)

// Operations are what Maintain can do to the tables of a database.
var Operations = []string{"check", "repair", "optimize", "analyze"}

// Identity is a Linux user something runs as: an account.
type Identity struct {
	UID  int
	GID  int
	Home string
}

// ImportError is a failed import, with what MariaDB or gzip said, which the
// account may see: it is about its own file.
type ImportError struct {
	Message string
}

func (e *ImportError) Error() string { return "the import failed: " + e.Message }

var storedHashPattern = regexp.MustCompile(`\*[0-9A-F]{40}`)

// Export writes a dump of database name of user to out: its tables, views,
// routines, triggers and events, without CREATE DATABASE, so it can be
// imported into a database of any name.
func (m *Manager) Export(ctx context.Context, user, name string, out io.Writer) error {
	if err := ValidateName(user, name, MaxDatabaseName); err != nil {
		return err
	}

	args := []string{
		"--single-transaction", "--quick", "--routines", "--triggers", "--events", "--hex-blob",
		"--default-character-set=utf8mb4", "--skip-dump-date", name,
	}

	if err := m.cfg.Dump(ctx, args, out); err != nil {
		return fmt.Errorf("%w: %w", ErrServer, err)
	}

	return nil
}

// Import runs the SQL of file (plain or gzip) in database name of user, as
// the account's Linux user, signed in as its owner user through
// unix_socket: the SQL reaches only the account's own databases, and the
// file is read with the account's own permissions. The client's own
// commands (\! and the like) are off. A failure the account should see is
// an *ImportError.
func (m *Manager) Import(ctx context.Context, as Identity, user, name, file string) error {
	if err := ValidateName(user, name, MaxDatabaseName); err != nil {
		return err
	}

	if !strings.HasPrefix(file, as.Home+"/") {
		return errors.New("the file to import must be in the account's home")
	}

	if err := m.ensureOwner(ctx, user, name); err != nil {
		return err
	}

	args := []string{
		"--no-defaults", "--socket=" + socketPath, "--user=" + user, "--database=" + name,
		"--binary-mode", "--batch", "--default-character-set=utf8mb4",
	}

	return m.cfg.Import(ctx, as, file, args)
}

// Maintain runs operation (one of Operations) on every table of database
// name of user and returns what MariaDB reported, one line per table or
// message.
func (m *Manager) Maintain(ctx context.Context, user, name, operation string) ([]string, error) {
	if err := ValidateName(user, name, MaxDatabaseName); err != nil {
		return nil, err
	}

	if !slices.Contains(Operations, operation) {
		return nil, fmt.Errorf("operation %q must be one of %v", operation, Operations)
	}

	output, err := m.cfg.Run(ctx, nil, checkProgram, "--"+operation, name)
	if err != nil {
		return nil, fmt.Errorf("%w: %w", ErrServer, err)
	}

	var lines []string

	for line := range strings.Lines(string(bytes.TrimSpace(output))) {
		if line = strings.TrimRight(line, "\r\n"); line != "" && len(lines) < 2000 {
			lines = append(lines, line)
		}
	}

	return lines, nil
}

// RemoteAccess reports whether MariaDB answers on every address.
func (m *Manager) RemoteAccess() bool {
	_, err := os.Stat(m.cfg.RemoteConfig)

	return err == nil
}

// SetRemoteAccess makes MariaDB answer on every address, for the remote
// hosts accounts allow, or only on 127.0.0.1 again, and restarts it when
// that changed.
func (m *Manager) SetRemoteAccess(ctx context.Context, enabled bool) error {
	if enabled == m.RemoteAccess() {
		return nil
	}

	if enabled {
		content := "# Written by rayxod: MariaDB answers on every address, for the remote hosts\n" +
			"# hosting accounts allow. Turned off in the panel's server settings.\n" +
			"[mariadbd]\nbind-address = *\n"

		if err := os.WriteFile(m.cfg.RemoteConfig, []byte(content), 0o644); err != nil {
			return fmt.Errorf("write %s: %w", m.cfg.RemoteConfig, err)
		}
	} else if err := os.Remove(m.cfg.RemoteConfig); err != nil && !errors.Is(err, os.ErrNotExist) {
		return fmt.Errorf("remove %s: %w", m.cfg.RemoteConfig, err)
	}

	if _, err := m.cfg.Run(ctx, nil, "/usr/bin/systemctl", "restart", "mariadb"); err != nil {
		return fmt.Errorf("%w: restart MariaDB: %w", ErrServer, err)
	}

	return nil
}

// ensureOwner makes sure the owner user of user exists, signs in through
// unix_socket, and has all privileges on database. Its password stays what
// it was, so an open phpMyAdmin session goes on; a new owner gets one
// nobody knows.
func (m *Manager) ensureOwner(ctx context.Context, user, database string) error {
	if err := validate.Username(user); err != nil {
		return err
	}

	rows, err := m.query(ctx, "SELECT Priv FROM mysql.global_priv WHERE User = "+literal(user)+" AND Host = "+literal(host)+";")
	if err != nil {
		return err
	}

	hash := ""
	if len(rows) > 0 {
		hash = storedHashPattern.FindString(rows[0])
	}

	if hash == "" {
		secret := make([]byte, 20)
		if _, err := rand.Read(secret); err != nil {
			return err
		}

		hash = "*" + strings.ToUpper(hex.EncodeToString(secret))
	}

	_, err = m.exec(ctx, "CREATE USER IF NOT EXISTS "+account(user)+" "+ownerIdentification(hash)+";\n"+
		"ALTER USER "+account(user)+" "+ownerIdentification(hash)+";\n"+
		"GRANT ALL PRIVILEGES ON "+grantTarget(database)+" TO "+account(user)+";")

	return err
}

// dump runs mariadb-dump as root with args, writing the dump to out.
func dump(ctx context.Context, args []string, out io.Writer) error {
	var stderr bytes.Buffer

	cmd := exec.CommandContext(ctx, dumpProgram, args...)
	cmd.Stdout = out
	cmd.Stderr = &stderr

	if err := cmd.Run(); err != nil {
		return fmt.Errorf("%w: %s", err, lastLine(stderr.String()))
	}

	return nil
}

// importAs runs gzip -dcf file | mariadb args as as, with nothing of the
// agent's environment.
func importAs(ctx context.Context, as Identity, file string, args []string) error {
	env := []string{"HOME=" + as.Home, "PATH=/usr/bin:/bin", "LC_ALL=C.UTF-8"}

	read := exec.CommandContext(ctx, gzipProgram, "-dcf", "--", file)
	load := exec.CommandContext(ctx, clientProgram, args...)

	for _, cmd := range []*exec.Cmd{read, load} {
		cmd.Dir = as.Home
		cmd.Env = env
		cmd.SysProcAttr = sysProcAttr(as)
	}

	var readErr, loadErr limitedBuffer
	readErr.limit, loadErr.limit = 4096, 4096
	read.Stderr = &readErr
	load.Stderr = &loadErr

	pipe, err := read.StdoutPipe()
	if err != nil {
		return err
	}

	load.Stdin = pipe

	if err := read.Start(); err != nil {
		return err
	}

	if err := load.Start(); err != nil {
		_ = read.Process.Kill()
		_ = read.Wait()

		return err
	}

	loadWait := load.Wait()
	readWait := read.Wait()

	switch {
	case loadWait != nil:
		return &ImportError{Message: firstLine(loadErr.String(), loadWait)}
	case readWait != nil:
		return &ImportError{Message: firstLine(readErr.String(), readWait)}
	}

	return nil
}

// firstLine is what a program said went wrong: its first ERROR line (the
// mariadb client prints the failing statement between dashes before it),
// else its first line with words, else its error.
func firstLine(output string, err error) string {
	fallback := ""

	for line := range strings.Lines(output) {
		line = strings.TrimSpace(line)

		if strings.HasPrefix(line, "ERROR") {
			return truncate(line)
		}

		if fallback == "" && strings.Trim(line, "- ") != "" {
			fallback = line
		}
	}

	if fallback != "" {
		return truncate(fallback)
	}

	return truncate(err.Error())
}

// lastLine is the last line of output.
func lastLine(output string) string {
	lines := strings.Split(strings.TrimSpace(output), "\n")

	return truncate(lines[len(lines)-1])
}

func truncate(message string) string {
	if len(message) > maxMessage {
		return message[:maxMessage] + "…"
	}

	return message
}

// limitedBuffer keeps the first limit bytes written to it.
type limitedBuffer struct {
	bytes.Buffer
	limit int
}

func (b *limitedBuffer) Write(p []byte) (int, error) {
	if room := b.limit - b.Len(); room > 0 {
		if len(p) > room {
			b.Buffer.Write(p[:room])
		} else {
			b.Buffer.Write(p)
		}
	}

	return len(p), nil
}
