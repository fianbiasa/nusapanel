// Package database manages the MariaDB databases and users of hosting
// accounts. It runs the mariadb client as root over the local socket, with
// the SQL on stdin, so nothing of it shows in the process list.
//
// Every database and user of an account is named <username>_<name>. That
// prefix is how the agent tells whose they are: usernames have no
// underscore, so no account's prefix is the start of another's. Names only
// hold a-z, 0-9 and underscores and password hashes only hex digits, so
// nothing a caller sends can change the SQL; every method validates again.
//
// Each account also has an owner user named exactly <username>, with access
// to all of its databases, which phpMyAdmin signs in as. Its password is set
// when phpMyAdmin opens and replaced by an unknown one when the session
// ends. It also signs in through unix_socket: a program running as the
// account's Linux user (imports run that way) is the owner without any
// password. It is never dropped while the account exists, because views,
// triggers and routines made in phpMyAdmin keep it as their definer.
//
// The other users connect from this server (localhost) and from the remote
// hosts their account allows, each a MariaDB user of its own with the same
// password and access. The owner user only connects locally.
package database

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"regexp"
	"slices"
	"strconv"
	"strings"

	"rayxo/agent/internal/runner"
	"rayxo/agent/internal/validate"
)

const (
	// MaxDatabaseName is the longest database name MariaDB allows.
	MaxDatabaseName = 64
	// MaxUserName keeps user names within what MySQL and older MariaDB
	// allow too, so they survive moving to another server.
	MaxUserName = 32

	clientProgram = "/usr/bin/mariadb"
	// host is the host every account user connects from: this server.
	host = "localhost"
	// MaxRemoteHosts is the most remote hosts one account may allow.
	MaxRemoteHosts = 20
)

var (
	namePattern = regexp.MustCompile(`^[a-z0-9_]+$`)
	// hashPattern is a mysql_native_password hash: "*" and the uppercase hex
	// SHA-1 of the SHA-1 of the password.
	hashPattern = regexp.MustCompile(`^\*[0-9A-F]{40}$`)
	// accountNamePattern matches the name of any account's database.
	accountNamePattern = regexp.MustCompile(`^[a-z][a-z0-9]{2,15}_[a-z0-9_]+$`)
	// grantPatternPattern matches the database of a grant this package
	// made, as mysql.db keeps it: a name with its underscores escaped.
	grantPatternPattern = regexp.MustCompile(`^[a-z0-9_\\]+$`)
)

// systemSchemas are MariaDB's own databases. Account names can never be
// them (the usernames are reserved); this is a second line of defence.
var systemSchemas = []string{"information_schema", "performance_schema", "mysql", "sys"}

// ErrServer means MariaDB refused or failed a command; the agent log has
// its message.
var ErrServer = errors.New("the database server could not do it")

// Config holds what the Manager needs from the system, so tests can run
// without MariaDB.
type Config struct {
	// Run runs a program with input on stdin and returns its stdout, like
	// runner.RunInput.
	Run func(ctx context.Context, input []byte, program string, args ...string) ([]byte, error)
	// Dump runs mariadb-dump as root with args, writing the dump to out.
	Dump func(ctx context.Context, args []string, out io.Writer) error
	// Import runs `gzip -dcf -- file | mariadb args...` as as. A failure
	// the account should see is an *ImportError.
	Import func(ctx context.Context, as Identity, file string, args []string) error
	// RemoteConfig is the MariaDB configuration file that makes it answer
	// on every address while it exists.
	RemoteConfig string
}

// DefaultConfig returns the operations used on a server.
func DefaultConfig() Config {
	return Config{
		Run:          runner.RunInput,
		Dump:         dump,
		Import:       importAs,
		RemoteConfig: "/etc/mysql/mariadb.conf.d/99-rayxo-remote.cnf",
	}
}

// Manager applies database changes. Callers must make sure only one call
// changes a given account at a time (the agent's account lock).
type Manager struct {
	cfg Config
}

// New returns a Manager for cfg. It does not touch the system.
func New(cfg Config) *Manager {
	return &Manager{cfg: cfg}
}

// ValidateName returns an error unless name is a valid name of a database
// (max MaxDatabaseName) or user (max MaxUserName) of account user:
// <user>_ followed by lowercase letters, digits and underscores.
func ValidateName(user, name string, maxLength int) error {
	if err := validate.Username(user); err != nil {
		return err
	}

	suffix, ok := strings.CutPrefix(name, user+"_")
	if !ok || suffix == "" {
		return fmt.Errorf("name %q must start with %s_", name, user)
	}

	if !namePattern.MatchString(suffix) || len(name) > maxLength {
		return fmt.Errorf("name %q may only hold lowercase letters, digits and underscores, at most %d characters", name, maxLength)
	}

	return nil
}

// ValidatePasswordHash returns an error unless hash is a
// mysql_native_password hash.
func ValidatePasswordHash(hash string) error {
	if !hashPattern.MatchString(hash) {
		return errors.New("password_hash must be a mysql_native_password hash (* and 40 uppercase hex digits)")
	}

	return nil
}

// CreateDatabase creates database name of user (utf8mb4) unless it exists.
func (m *Manager) CreateDatabase(ctx context.Context, user, name string) error {
	if err := ValidateName(user, name, MaxDatabaseName); err != nil {
		return err
	}

	_, err := m.exec(ctx, "CREATE DATABASE IF NOT EXISTS "+identifier(name)+
		" CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;")

	return err
}

// DeleteDatabase drops database name of user and every user's access to
// it, so a database created later under the same name starts without any.
// A database that does not exist succeeds.
func (m *Manager) DeleteDatabase(ctx context.Context, user, name string) error {
	if err := ValidateName(user, name, MaxDatabaseName); err != nil {
		return err
	}

	grantees, err := m.query(ctx, "SELECT User, Host FROM mysql.db WHERE Db = "+literal(grantPattern(name))+";")
	if err != nil {
		return err
	}

	var sql strings.Builder

	for _, row := range grantees {
		grantee, from, ok := strings.Cut(row, "\t")
		if ok && namePattern.MatchString(grantee) && validHostColumn(from) {
			sql.WriteString("REVOKE ALL PRIVILEGES ON " + grantTarget(name) + " FROM " + accountAt(grantee, from) + ";\n")
		}
	}

	sql.WriteString("DROP DATABASE IF EXISTS " + identifier(name) + ";")

	_, err = m.exec(ctx, sql.String())

	return err
}

// ApplyUser creates user name of user, or updates it: it gets the password
// of hash and all privileges on exactly databases, which must be databases
// of user (they need not exist yet), from this server and from each of
// remoteHosts (see ValidateHost), and from no other host. Running it again
// changes nothing.
func (m *Manager) ApplyUser(ctx context.Context, user, name, hash string, databases, remoteHosts []string) error {
	if err := ValidateName(user, name, MaxUserName); err != nil {
		return err
	}

	if err := ValidateHosts(remoteHosts); err != nil {
		return err
	}

	return m.applyUser(ctx, user, name, hash, databases, remoteHosts, false)
}

// ApplyOwner creates the owner user of account user (named user), or
// updates it like ApplyUser: the password of hash and all privileges on
// exactly databases.
func (m *Manager) ApplyOwner(ctx context.Context, user, hash string, databases []string) error {
	if err := validate.Username(user); err != nil {
		return err
	}

	return m.applyUser(ctx, user, user, hash, databases, nil, true)
}

// CloseOwner gives the owner user of account user a random password nobody
// knows, so whoever had the last one can no longer sign in. An owner that
// does not exist succeeds.
func (m *Manager) CloseOwner(ctx context.Context, user string) error {
	if err := validate.Username(user); err != nil {
		return err
	}

	secret := make([]byte, 20)
	if _, err := rand.Read(secret); err != nil {
		return err
	}

	// Any "*" and 40 hex digits is a valid hash; random ones match no
	// password anyone knows.
	hash := "*" + strings.ToUpper(hex.EncodeToString(secret))

	_, err := m.exec(ctx, "ALTER USER IF EXISTS "+account(user)+" "+ownerIdentification(hash)+";")

	return err
}

// applyUser does ApplyUser and ApplyOwner for user name of account user,
// which the caller validated, with remoteHosts validated too. The owner also
// signs in through unix_socket.
func (m *Manager) applyUser(ctx context.Context, user, name, hash string, databases, remoteHosts []string, owner bool) error {
	if err := ValidatePasswordHash(hash); err != nil {
		return err
	}

	for _, database := range databases {
		if err := ValidateName(user, database, MaxDatabaseName); err != nil {
			return err
		}
	}

	identification := "IDENTIFIED BY PASSWORD " + literal(hash)
	if owner {
		identification = ownerIdentification(hash)
	}

	hosts := append([]string{host}, remoteHosts...)

	existing, err := m.query(ctx, "SELECT Host FROM mysql.user WHERE User = "+literal(name)+";")
	if err != nil {
		return err
	}

	current, err := m.query(ctx, "SELECT Host, Db FROM mysql.db WHERE User = "+literal(name)+";")
	if err != nil {
		return err
	}

	desired := make([]string, 0, len(databases))
	for _, database := range databases {
		desired = append(desired, grantPattern(database))
	}

	var sql strings.Builder

	// Hosts the account no longer allows go first.
	for _, old := range existing {
		if !slices.Contains(hosts, old) && validHostColumn(old) {
			sql.WriteString("DROP USER IF EXISTS " + accountAt(name, old) + ";\n")
		}
	}

	for _, from := range hosts {
		target := accountAt(name, from)

		sql.WriteString("CREATE USER IF NOT EXISTS " + target + " " + identification + ";\n")
		sql.WriteString("ALTER USER " + target + " " + identification + ";\n")

		for _, row := range current {
			grantHost, pattern, ok := strings.Cut(row, "\t")
			if ok && grantHost == from && grantPatternPattern.MatchString(pattern) && !slices.Contains(desired, pattern) {
				sql.WriteString("REVOKE ALL PRIVILEGES ON `" + pattern + "`.* FROM " + target + ";\n")
			}
		}

		for _, database := range databases {
			sql.WriteString("GRANT ALL PRIVILEGES ON " + grantTarget(database) + " TO " + target + ";\n")
		}
	}

	_, err = m.exec(ctx, sql.String())

	return err
}

// ownerIdentification is how the owner user signs in: with the password of
// hash, or as the account's Linux user through the server socket.
func ownerIdentification(hash string) string {
	return "IDENTIFIED VIA mysql_native_password USING " + literal(hash) + " OR unix_socket"
}

// DeleteUser drops user name of user, from every host. A user that does
// not exist succeeds.
func (m *Manager) DeleteUser(ctx context.Context, user, name string) error {
	if err := ValidateName(user, name, MaxUserName); err != nil {
		return err
	}

	targets, err := m.userAccounts(ctx, "User = "+literal(name))
	if err != nil {
		return err
	}

	var sql strings.Builder

	sql.WriteString("DROP USER IF EXISTS " + account(name) + ";\n")

	for _, target := range targets {
		sql.WriteString("DROP USER IF EXISTS " + target + ";\n")
	}

	_, err = m.exec(ctx, sql.String())

	return err
}

// userAccounts returns the users matching where, each as user@host for
// SQL, except those of hosts no account user can have.
func (m *Manager) userAccounts(ctx context.Context, where string) ([]string, error) {
	rows, err := m.query(ctx, "SELECT User, Host FROM mysql.user WHERE "+where+";")
	if err != nil {
		return nil, err
	}

	var targets []string

	for _, row := range rows {
		name, from, ok := strings.Cut(row, "\t")
		if ok && namePattern.MatchString(name) && validHostColumn(from) {
			targets = append(targets, accountAt(name, from))
		}
	}

	return targets, nil
}

// RemoveAccount drops every database and user of user, its owner user
// included, so an account created later with the same username starts
// without them.
func (m *Manager) RemoveAccount(ctx context.Context, user string) error {
	databases, users, err := m.accountObjects(ctx, user)
	if err != nil {
		return err
	}

	var sql strings.Builder

	sql.WriteString("DROP USER IF EXISTS " + account(user) + ";\n")

	for _, target := range users {
		sql.WriteString("DROP USER IF EXISTS " + target + ";\n")
	}

	for _, name := range databases {
		sql.WriteString("DELETE FROM mysql.db WHERE Db = " + literal(grantPattern(name)) + ";\n")
		sql.WriteString("DROP DATABASE IF EXISTS " + identifier(name) + ";\n")
	}

	sql.WriteString("FLUSH PRIVILEGES;")

	_, err = m.exec(ctx, sql.String())

	return err
}

// LockAccount locks every user of user, its owner user included, so
// nothing logs in to the account's databases while it is suspended, or
// unlocks them again.
func (m *Manager) LockAccount(ctx context.Context, user string, lock bool) error {
	_, users, err := m.accountObjects(ctx, user)
	if err != nil {
		return err
	}

	state := "ACCOUNT UNLOCK"
	if lock {
		state = "ACCOUNT LOCK"
	}

	var sql strings.Builder

	// The owner user only exists once phpMyAdmin has been opened.
	sql.WriteString("ALTER USER IF EXISTS " + account(user) + " " + state + ";\n")

	for _, target := range users {
		sql.WriteString("ALTER USER " + target + " " + state + ";\n")
	}

	_, err = m.exec(ctx, sql.String())

	return err
}

// Size is the size of one account database.
type Size struct {
	Name  string `json:"name"`
	Bytes int64  `json:"size_bytes"`
}

// Report returns the size of every account database: its tables' data and
// indexes. A database without tables has size 0.
func (m *Manager) Report(ctx context.Context) ([]Size, error) {
	rows, err := m.query(ctx, "SELECT s.SCHEMA_NAME, COALESCE(SUM(t.DATA_LENGTH + t.INDEX_LENGTH), 0)"+
		" FROM information_schema.SCHEMATA s"+
		" LEFT JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = s.SCHEMA_NAME"+
		" GROUP BY s.SCHEMA_NAME ORDER BY s.SCHEMA_NAME;")
	if err != nil {
		return nil, err
	}

	sizes := []Size{}

	for _, row := range rows {
		name, value, ok := strings.Cut(row, "\t")
		if !ok || !accountNamePattern.MatchString(name) || slices.Contains(systemSchemas, name) {
			continue
		}

		bytes, err := strconv.ParseInt(value, 10, 64)
		if err != nil {
			continue
		}

		sizes = append(sizes, Size{Name: name, Bytes: bytes})
	}

	return sizes, nil
}

// accountObjects returns the names of the databases of user, and its users
// (except the owner) as user@host for SQL, from every host.
func (m *Manager) accountObjects(ctx context.Context, user string) ([]string, []string, error) {
	if err := validate.Username(user); err != nil {
		return nil, nil, err
	}

	prefix := literal(user + `\_%`)

	databases, err := m.query(ctx, "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE "+prefix+";")
	if err != nil {
		return nil, nil, err
	}

	users, err := m.userAccounts(ctx, "User LIKE "+prefix)
	if err != nil {
		return nil, nil, err
	}

	databases = slices.DeleteFunc(databases, func(name string) bool {
		return ValidateName(user, name, MaxDatabaseName) != nil || slices.Contains(systemSchemas, name)
	})

	return databases, users, nil
}

// exec runs sql, which stops at the first statement that fails.
func (m *Manager) exec(ctx context.Context, sql string) ([]byte, error) {
	output, err := m.cfg.Run(ctx, []byte(sql+"\n"), clientProgram, "--batch", "--raw", "--skip-column-names")
	if err != nil {
		return nil, fmt.Errorf("%w: %w", ErrServer, err)
	}

	return output, nil
}

// query runs sql and returns its rows, one tab-separated line each.
func (m *Manager) query(ctx context.Context, sql string) ([]string, error) {
	output, err := m.exec(ctx, sql)
	if err != nil {
		return nil, err
	}

	var rows []string

	for line := range strings.Lines(string(bytes.TrimSpace(output))) {
		if line = strings.TrimRight(line, "\r\n"); line != "" {
			rows = append(rows, line)
		}
	}

	return rows, nil
}

// identifier quotes a validated name as an SQL identifier.
func identifier(name string) string {
	return "`" + name + "`"
}

// literal quotes a validated value as an SQL string. Validated names and
// hashes hold no quotes; the backslash of a LIKE or grant pattern is
// doubled, as MariaDB strings read it as an escape.
func literal(value string) string {
	return "'" + strings.ReplaceAll(value, `\`, `\\`) + "'"
}

// account names user name on this server as MariaDB expects it.
func account(name string) string {
	return accountAt(name, host)
}

// accountAt names user name connecting from from (localhost or a host that
// passed ValidateHost) as MariaDB expects it.
func accountAt(name, from string) string {
	return literal(name) + "@" + literal(from)
}

// grantPattern is the database part of a grant for database: in grants _ is
// a wildcard, so it is escaped to mean only itself.
func grantPattern(database string) string {
	return strings.ReplaceAll(database, "_", `\_`)
}

// grantTarget is what a GRANT or REVOKE on all of database names.
func grantTarget(database string) string {
	return "`" + grantPattern(database) + "`.*"
}
