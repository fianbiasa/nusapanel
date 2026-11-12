package database

import (
	"context"
	"errors"
	"io"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
)

// operationsManager is a Manager whose dump and import are faked too.
type operationsManager struct {
	*Manager
	client  *fakeClient
	dumped  [][]string
	imports []string
	failed  error
}

func newOperationsManager(t *testing.T) *operationsManager {
	t.Helper()

	client := &fakeClient{answers: map[string]string{}, outputs: map[string]string{}}
	m := &operationsManager{client: client}
	m.Manager = New(Config{
		Run: client.run,
		Dump: func(_ context.Context, args []string, out io.Writer) error {
			m.dumped = append(m.dumped, args)
			_, err := io.WriteString(out, "-- dump\n")

			return err
		},
		Import: func(_ context.Context, as Identity, file string, args []string) error {
			m.imports = append(m.imports, as.Home+" "+file+" "+strings.Join(args, " "))

			return m.failed
		},
		RemoteConfig: filepath.Join(t.TempDir(), "99-rayxo-remote.cnf"),
	})

	return m
}

func TestExportDumpsOnlyTheDatabaseWithoutCreatingIt(t *testing.T) {
	m := newOperationsManager(t)

	var out strings.Builder
	if err := m.Export(context.Background(), "budi", "budi_shop", &out); err != nil {
		t.Fatal(err)
	}

	want := []string{"--single-transaction", "--quick", "--routines", "--triggers", "--events", "--hex-blob", "--default-character-set=utf8mb4", "--skip-dump-date", "budi_shop"}
	if len(m.dumped) != 1 || !slices.Equal(m.dumped[0], want) || out.String() != "-- dump\n" {
		t.Fatalf("dumped %q into %q", m.dumped, out.String())
	}
}

func TestImportRunsAsTheAccountSignedInAsItsOwner(t *testing.T) {
	m := newOperationsManager(t)
	// The owner already has a password (phpMyAdmin is open): it is kept.
	m.client.answers["FROM mysql.global_priv"] = `{"auth_or":[{"plugin":"mysql_native_password","authentication_string":"` + hash + `"},{"plugin":"unix_socket"}]}`

	as := Identity{UID: 1001, GID: 1001, Home: "/home/budi"}
	if err := m.Import(context.Background(), as, "budi", "budi_shop", "/home/budi/tmp/shop.sql.gz"); err != nil {
		t.Fatal(err)
	}

	identified := "IDENTIFIED VIA mysql_native_password USING '" + hash + "' OR unix_socket"
	if len(m.client.sql) != 2 || m.client.sql[1] != "CREATE USER IF NOT EXISTS 'budi'@'localhost' "+identified+";\n"+
		"ALTER USER 'budi'@'localhost' "+identified+";\n"+
		"GRANT ALL PRIVILEGES ON `budi\\_shop`.* TO 'budi'@'localhost';" {
		t.Fatalf("sql =\n%s", strings.Join(m.client.sql, "\n---\n"))
	}

	// The client's own commands are off (--binary-mode), and it reads no
	// option file the account could have written.
	want := "/home/budi /home/budi/tmp/shop.sql.gz --no-defaults --socket=/run/mysqld/mysqld.sock --user=budi --database=budi_shop --binary-mode --batch --default-character-set=utf8mb4"
	if len(m.imports) != 1 || m.imports[0] != want {
		t.Fatalf("imports = %q", m.imports)
	}
}

func TestANewOwnerGetsAPasswordNobodyKnows(t *testing.T) {
	m := newOperationsManager(t)

	if err := m.Import(context.Background(), Identity{Home: "/home/budi"}, "budi", "budi_shop", "/home/budi/a.sql"); err != nil {
		t.Fatal(err)
	}

	if !strings.Contains(m.client.sql[1], "IDENTIFIED VIA mysql_native_password USING '*") || strings.Contains(m.client.sql[1], hash) {
		t.Fatalf("sql = %q", m.client.sql[1])
	}
}

func TestAFailedImportSaysWhatMariaDBSaid(t *testing.T) {
	m := newOperationsManager(t)
	m.failed = &ImportError{Message: "ERROR 1064 (42000) at line 3: You have an error in your SQL syntax"}

	err := m.Import(context.Background(), Identity{Home: "/home/budi"}, "budi", "budi_shop", "/home/budi/a.sql")

	var failed *ImportError
	if !errors.As(err, &failed) || !strings.Contains(failed.Message, "at line 3") {
		t.Fatalf("got %v", err)
	}
}

func TestMaintainRunsMariadbCheckOnTheDatabase(t *testing.T) {
	m := newOperationsManager(t)
	m.client.outputs["/usr/bin/mariadb-check"] = "budi_shop.orders  OK\nbudi_shop.items   OK\n"

	lines, err := m.Maintain(context.Background(), "budi", "budi_shop", "optimize")
	if err != nil {
		t.Fatal(err)
	}

	if !slices.Equal(m.client.programs, []string{"/usr/bin/mariadb-check --optimize budi_shop"}) ||
		!slices.Equal(lines, []string{"budi_shop.orders  OK", "budi_shop.items   OK"}) {
		t.Fatalf("ran %q, got %q", m.client.programs, lines)
	}
}

func TestRemoteAccessWritesItsConfigAndRestartsOnlyOnAChange(t *testing.T) {
	m := newOperationsManager(t)
	ctx := context.Background()

	for range 2 {
		if err := m.SetRemoteAccess(ctx, true); err != nil {
			t.Fatal(err)
		}
	}

	content, err := os.ReadFile(m.cfg.RemoteConfig)
	if err != nil || !strings.Contains(string(content), "[mariadbd]\nbind-address = *\n") || !m.RemoteAccess() {
		t.Fatalf("config %q (%v)", content, err)
	}

	if err := m.SetRemoteAccess(ctx, false); err != nil {
		t.Fatal(err)
	}

	if m.RemoteAccess() {
		t.Fatal("the config is still there")
	}

	if !slices.Equal(m.client.programs, []string{"/usr/bin/systemctl restart mariadb", "/usr/bin/systemctl restart mariadb"}) {
		t.Fatalf("ran %q", m.client.programs)
	}
}

func TestAFailureSaysTheErrorLineOfTheClient(t *testing.T) {
	output := "--------------\nCREATE TABLE mysql.pwned (id INT)\n--------------\n\nERROR 1142 (42000) at line 1: CREATE command denied to user 'budi'@'localhost' for table `mysql`.`pwned`\n"

	if got := firstLine(output, errors.New("exit status 1")); !strings.HasPrefix(got, "ERROR 1142 (42000) at line 1") {
		t.Fatalf("got %q", got)
	}

	if got := firstLine("gzip: a.sql.gz: unexpected end of file\n", errors.New("exit status 1")); got != "gzip: a.sql.gz: unexpected end of file" {
		t.Fatalf("got %q", got)
	}

	if got := firstLine("--------------\n", errors.New("exit status 1")); got != "exit status 1" {
		t.Fatalf("got %q", got)
	}
}
