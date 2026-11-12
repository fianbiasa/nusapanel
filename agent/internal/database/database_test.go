package database

import (
	"context"
	"errors"
	"io"
	"regexp"
	"strconv"
	"strings"
	"testing"

	"rayxo/agent/internal/runner"
)

// fakeClient records the SQL sent to the mariadb client and answers
// queries from canned rows, by a substring of their SQL.
type fakeClient struct {
	sql     []string
	answers map[string]string
	fail    string
	// programs records other programs run, as "<program> <args>", and
	// outputs answers them by program.
	programs []string
	outputs  map[string]string
}

func (f *fakeClient) run(_ context.Context, input []byte, program string, args ...string) ([]byte, error) {
	if program != "/usr/bin/mariadb" {
		f.programs = append(f.programs, program+" "+strings.Join(args, " "))

		return []byte(f.outputs[program]), nil
	}

	if strings.Join(args, " ") != "--batch --raw --skip-column-names" {
		return nil, errors.New("unexpected program " + program + " " + strings.Join(args, " "))
	}

	sql := string(input)
	f.sql = append(f.sql, strings.TrimSpace(sql))

	if f.fail != "" && strings.Contains(sql, f.fail) {
		return nil, &runner.Error{Program: program, ExitCode: 1, Output: "ERROR 1007"}
	}

	for key, rows := range f.answers {
		if strings.Contains(sql, key) {
			return []byte(rows), nil
		}
	}

	return nil, nil
}

func newTestManager() (*Manager, *fakeClient) {
	client := &fakeClient{answers: map[string]string{}, outputs: map[string]string{}}

	return New(Config{Run: client.run}), client
}

const hash = "*6BB4837EB74329105EE4568DDA7DC67ED2CA2AD9"

func TestValidateName(t *testing.T) {
	valid := []string{"budi_shop", "budi_wp_2026", "budi_a"}
	for _, name := range valid {
		if err := ValidateName("budi", name, MaxUserName); err != nil {
			t.Errorf("%s: %v", name, err)
		}
	}

	invalid := []string{
		"", "budi", "budi_", "shop", "sari_shop", "budishop", "budi_Shop", "budi_shop-1", "budi_shop`",
		"budi_shop'", "budi_shop;", "budi_shop%", "budi_\\_x", "budi_" + strings.Repeat("a", 28),
	}
	for _, name := range invalid {
		if err := ValidateName("budi", name, MaxUserName); err == nil {
			t.Errorf("%q was accepted", name)
		}
	}

	if ValidateName("root", "root_x", MaxUserName) == nil || ValidateName("Budi", "Budi_x", MaxUserName) == nil {
		t.Error("an invalid account was accepted")
	}
}

func TestValidatePasswordHash(t *testing.T) {
	if err := ValidatePasswordHash(hash); err != nil {
		t.Fatal(err)
	}

	for _, value := range []string{"", "secret", strings.ToLower(hash), hash + "0", "*" + strings.Repeat("G", 40), hash[:40] + "'"} {
		if ValidatePasswordHash(value) == nil {
			t.Errorf("%q was accepted", value)
		}
	}
}

func TestCreateDatabase(t *testing.T) {
	manager, client := newTestManager()

	if err := manager.CreateDatabase(context.Background(), "budi", "budi_shop"); err != nil {
		t.Fatal(err)
	}

	want := "CREATE DATABASE IF NOT EXISTS `budi_shop` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
	if len(client.sql) != 1 || client.sql[0] != want {
		t.Fatalf("sql = %q", client.sql)
	}
}

func TestDeleteDatabaseRevokesEveryGrantOnIt(t *testing.T) {
	manager, client := newTestManager()
	client.answers["FROM mysql.db"] = "budi_wp\tlocalhost\nbudi_wp\t203.0.113.7\nbudi_old\tlocalhost\n"

	if err := manager.DeleteDatabase(context.Background(), "budi", "budi_shop"); err != nil {
		t.Fatal(err)
	}

	// From every host the users connect from.
	want := []string{
		`SELECT User, Host FROM mysql.db WHERE Db = 'budi\\_shop';`,
		"REVOKE ALL PRIVILEGES ON `budi\\_shop`.* FROM 'budi_wp'@'localhost';\n" +
			"REVOKE ALL PRIVILEGES ON `budi\\_shop`.* FROM 'budi_wp'@'203.0.113.7';\n" +
			"REVOKE ALL PRIVILEGES ON `budi\\_shop`.* FROM 'budi_old'@'localhost';\n" +
			"DROP DATABASE IF EXISTS `budi_shop`;",
	}

	if strings.Join(client.sql, "\n---\n") != strings.Join(want, "\n---\n") {
		t.Fatalf("sql =\n%s", strings.Join(client.sql, "\n---\n"))
	}
}

func TestApplyUserSetsThePasswordAndExactlyTheGrants(t *testing.T) {
	manager, client := newTestManager()
	// Access to budi_old goes; budi_shop stays; budi_blog is new. The host
	// the account no longer allows goes; 203.0.113.7 is new.
	client.answers["SELECT Host FROM mysql.user"] = "localhost\n198.51.100.0/255.255.255.0\n"
	client.answers["SELECT Host, Db FROM mysql.db"] = "localhost\tbudi\\_shop\nlocalhost\tbudi\\_old\n198.51.100.0/255.255.255.0\tbudi\\_shop\n"

	if err := manager.ApplyUser(context.Background(), "budi", "budi_wp", hash, []string{"budi_shop", "budi_blog"}, []string{"203.0.113.7"}); err != nil {
		t.Fatal(err)
	}

	want := "DROP USER IF EXISTS 'budi_wp'@'198.51.100.0/255.255.255.0';\n" +
		"CREATE USER IF NOT EXISTS 'budi_wp'@'localhost' IDENTIFIED BY PASSWORD '" + hash + "';\n" +
		"ALTER USER 'budi_wp'@'localhost' IDENTIFIED BY PASSWORD '" + hash + "';\n" +
		"REVOKE ALL PRIVILEGES ON `budi\\_old`.* FROM 'budi_wp'@'localhost';\n" +
		"GRANT ALL PRIVILEGES ON `budi\\_shop`.* TO 'budi_wp'@'localhost';\n" +
		"GRANT ALL PRIVILEGES ON `budi\\_blog`.* TO 'budi_wp'@'localhost';\n" +
		"CREATE USER IF NOT EXISTS 'budi_wp'@'203.0.113.7' IDENTIFIED BY PASSWORD '" + hash + "';\n" +
		"ALTER USER 'budi_wp'@'203.0.113.7' IDENTIFIED BY PASSWORD '" + hash + "';\n" +
		"GRANT ALL PRIVILEGES ON `budi\\_shop`.* TO 'budi_wp'@'203.0.113.7';\n" +
		"GRANT ALL PRIVILEGES ON `budi\\_blog`.* TO 'budi_wp'@'203.0.113.7';"

	if len(client.sql) != 3 || client.sql[2] != want {
		t.Fatalf("sql =\n%s", strings.Join(client.sql, "\n---\n"))
	}
}

func TestValidateHost(t *testing.T) {
	for _, host := range []string{"%", "203.0.113.7", "10.%", "192.168.1.%", "192.168.1.0/255.255.255.0", "10.0.0.0/255.0.0.0", "2001:db8::1"} {
		if err := ValidateHost(host); err != nil {
			t.Errorf("%s: %v", host, err)
		}
	}

	for _, host := range []string{
		"", "localhost", "127.0.0.1", "::1", "0.0.0.0", "db.example.com", "%.example.com", "192.168.%.1", "192.168.1.0/24",
		"192.168.1.0/255.0.255.0", "300.1.1.1", "203.0.113.7'", "203.0.113.7 ", "2001:DB8::1", "fe80::1%eth0", "::ffff:1.2.3.4",
	} {
		if err := ValidateHost(host); err == nil {
			t.Errorf("%q was accepted", host)
		}
	}

	many := make([]string, MaxRemoteHosts+1)
	for i := range many {
		many[i] = "203.0.113." + strconv.Itoa(i+1)
	}

	if ValidateHosts(many) == nil || ValidateHosts([]string{"%", "%"}) == nil {
		t.Error("too many or repeated hosts were accepted")
	}
}

func TestApplyUserRefusesWhatIsNotTheAccounts(t *testing.T) {
	manager, client := newTestManager()
	ctx := context.Background()

	cases := []func() error{
		func() error { return manager.ApplyUser(ctx, "budi", "sari_wp", hash, nil, nil) },
		func() error { return manager.ApplyUser(ctx, "budi", "budi_wp", hash, []string{"sari_shop"}, nil) },
		func() error { return manager.ApplyUser(ctx, "budi", "budi_wp", hash, []string{"mysql"}, nil) },
		func() error { return manager.ApplyUser(ctx, "budi", "budi_wp", "plain password", nil, nil) },
		func() error { return manager.ApplyUser(ctx, "budi", "budi_wp' OR 1=1 --", hash, nil, nil) },
		func() error { return manager.ApplyUser(ctx, "budi", "budi_wp", hash, nil, []string{"x' OR 1=1"}) },
		func() error { return manager.ApplyUser(ctx, "budi", "budi_wp", hash, nil, []string{"localhost"}) },
		func() error { _, err := manager.Maintain(ctx, "budi", "budi_wp", "drop"); return err },
		func() error { _, err := manager.Maintain(ctx, "budi", "sari_wp", "check"); return err },
		func() error { return manager.Export(ctx, "budi", "sari_wp", io.Discard) },
		func() error {
			return manager.Import(ctx, Identity{Home: "/home/budi"}, "budi", "sari_wp", "/home/budi/x.sql")
		},
		func() error {
			return manager.Import(ctx, Identity{Home: "/home/budi"}, "budi", "budi_wp", "/home/sari/x.sql")
		},
		func() error { return manager.CreateDatabase(ctx, "budi", "rayxo") },
		func() error { return manager.DeleteDatabase(ctx, "budi", "sari_shop") },
		func() error { return manager.DeleteUser(ctx, "budi", "root") },
		func() error { return manager.RemoveAccount(ctx, "root") },
		func() error { return manager.ApplyOwner(ctx, "root", hash, nil) },
		func() error { return manager.ApplyOwner(ctx, "rayxo", hash, nil) },
		func() error { return manager.ApplyOwner(ctx, "budi", hash, []string{"sari_shop"}) },
		func() error { return manager.ApplyOwner(ctx, "budi", "plain password", nil) },
		func() error { return manager.CloseOwner(ctx, "root") },
		func() error { return manager.LockAccount(ctx, "mysql", true) },
	}

	for i, call := range cases {
		if err := call(); err == nil {
			t.Errorf("case %d was accepted", i)
		}
	}

	if len(client.sql) != 0 {
		t.Fatalf("SQL ran: %q", client.sql)
	}
}

func TestApplyOwnerGivesTheAccountNameAccessToItsDatabases(t *testing.T) {
	manager, client := newTestManager()
	client.answers["SELECT Host, Db FROM mysql.db"] = "localhost\tbudi\\_old\n"

	if err := manager.ApplyOwner(context.Background(), "budi", hash, []string{"budi_shop", "budi_wp"}); err != nil {
		t.Fatal(err)
	}

	if client.sql[1] != "SELECT Host, Db FROM mysql.db WHERE User = 'budi';" {
		t.Fatalf("grants looked up with %q", client.sql[1])
	}

	// The account's own Linux user signs in as it without a password.
	identified := "IDENTIFIED VIA mysql_native_password USING '" + hash + "' OR unix_socket"
	want := "CREATE USER IF NOT EXISTS 'budi'@'localhost' " + identified + ";\n" +
		"ALTER USER 'budi'@'localhost' " + identified + ";\n" +
		"REVOKE ALL PRIVILEGES ON `budi\\_old`.* FROM 'budi'@'localhost';\n" +
		"GRANT ALL PRIVILEGES ON `budi\\_shop`.* TO 'budi'@'localhost';\n" +
		"GRANT ALL PRIVILEGES ON `budi\\_wp`.* TO 'budi'@'localhost';"

	if len(client.sql) != 3 || client.sql[2] != want {
		t.Fatalf("sql =\n%s", strings.Join(client.sql, "\n---\n"))
	}
}

func TestCloseOwnerGivesItAnUnknownPassword(t *testing.T) {
	manager, client := newTestManager()

	for range 2 {
		if err := manager.CloseOwner(context.Background(), "budi"); err != nil {
			t.Fatal(err)
		}
	}

	pattern := regexp.MustCompile(`^ALTER USER IF EXISTS 'budi'@'localhost' IDENTIFIED VIA mysql_native_password USING '\*[0-9A-F]{40}' OR unix_socket;$`)
	if len(client.sql) != 2 || !pattern.MatchString(client.sql[0]) || !pattern.MatchString(client.sql[1]) {
		t.Fatalf("sql = %q", client.sql)
	}

	if client.sql[0] == client.sql[1] {
		t.Fatal("both calls set the same password")
	}
}

func TestRemoveAccountDropsItsDatabasesAndUsers(t *testing.T) {
	manager, client := newTestManager()
	client.answers["information_schema.SCHEMATA WHERE"] = "budi_shop\nbudi_blog\n"
	client.answers["FROM mysql.user"] = "budi_wp\tlocalhost\nbudi_wp\t203.0.113.7\n"

	if err := manager.RemoveAccount(context.Background(), "budi"); err != nil {
		t.Fatal(err)
	}

	if !strings.Contains(client.sql[0], `LIKE 'budi\\_%'`) || !strings.Contains(client.sql[1], `LIKE 'budi\\_%'`) {
		t.Fatalf("the account's objects were not looked up by their prefix: %q", client.sql[:2])
	}

	want := "DROP USER IF EXISTS 'budi'@'localhost';\n" +
		"DROP USER IF EXISTS 'budi_wp'@'localhost';\n" +
		"DROP USER IF EXISTS 'budi_wp'@'203.0.113.7';\n" +
		"DELETE FROM mysql.db WHERE Db = 'budi\\\\_shop';\n" +
		"DROP DATABASE IF EXISTS `budi_shop`;\n" +
		"DELETE FROM mysql.db WHERE Db = 'budi\\\\_blog';\n" +
		"DROP DATABASE IF EXISTS `budi_blog`;\n" +
		"FLUSH PRIVILEGES;"

	if len(client.sql) != 3 || client.sql[2] != want {
		t.Fatalf("sql =\n%s", strings.Join(client.sql, "\n---\n"))
	}
}

func TestRemoveAccountWithoutDatabasesStillDropsTheOwnerUser(t *testing.T) {
	manager, client := newTestManager()

	if err := manager.RemoveAccount(context.Background(), "budi"); err != nil {
		t.Fatal(err)
	}

	if len(client.sql) != 3 || client.sql[2] != "DROP USER IF EXISTS 'budi'@'localhost';\nFLUSH PRIVILEGES;" {
		t.Fatalf("sql = %q", client.sql)
	}
}

func TestLockAccountLocksAndUnlocksItsUsers(t *testing.T) {
	manager, client := newTestManager()
	client.answers["FROM mysql.user"] = "budi_wp\tlocalhost\nbudi_shop\tlocalhost\n"

	if err := manager.LockAccount(context.Background(), "budi", true); err != nil {
		t.Fatal(err)
	}

	if err := manager.LockAccount(context.Background(), "budi", false); err != nil {
		t.Fatal(err)
	}

	if client.sql[2] != "ALTER USER IF EXISTS 'budi'@'localhost' ACCOUNT LOCK;\n"+
		"ALTER USER 'budi_wp'@'localhost' ACCOUNT LOCK;\nALTER USER 'budi_shop'@'localhost' ACCOUNT LOCK;" ||
		client.sql[5] != "ALTER USER IF EXISTS 'budi'@'localhost' ACCOUNT UNLOCK;\n"+
			"ALTER USER 'budi_wp'@'localhost' ACCOUNT UNLOCK;\nALTER USER 'budi_shop'@'localhost' ACCOUNT UNLOCK;" {
		t.Fatalf("sql =\n%s", strings.Join(client.sql, "\n---\n"))
	}
}

func TestReportListsOnlyAccountDatabases(t *testing.T) {
	manager, client := newTestManager()
	client.answers["information_schema.TABLES"] = "budi_blog\t0\nbudi_shop\t1048576\ninformation_schema\t0\nmysql\t2785280\nrayxo\t655360\nsari_wp\t98304\n"

	sizes, err := manager.Report(context.Background())
	if err != nil {
		t.Fatal(err)
	}

	want := []Size{{"budi_blog", 0}, {"budi_shop", 1048576}, {"sari_wp", 98304}}
	if len(sizes) != len(want) {
		t.Fatalf("sizes = %+v", sizes)
	}

	for i := range want {
		if sizes[i] != want[i] {
			t.Fatalf("sizes = %+v", sizes)
		}
	}
}

func TestFailuresAreServerErrors(t *testing.T) {
	manager, client := newTestManager()
	client.fail = "CREATE DATABASE"

	if err := manager.CreateDatabase(context.Background(), "budi", "budi_shop"); !errors.Is(err, ErrServer) {
		t.Fatalf("got %v, want ErrServer", err)
	}
}
