package actions

import (
	"bytes"
	"cmp"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"log/slog"
	"maps"
	"path/filepath"
	"reflect"
	"runtime"
	"slices"
	"strings"
	"testing"

	"rayxo/agent/internal/cgroup"
	"rayxo/agent/internal/database"
	"rayxo/agent/internal/jail"
	"rayxo/agent/internal/quota"
	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/runner"
	"rayxo/agent/internal/sysuser"
	"rayxo/agent/internal/webserver"
)

var accountMethodNames = []string{"account.create", "account.suspend", "account.unsuspend", "account.delete", "account.set_password"}

func TestAccountMethodsAreRegistered(t *testing.T) {
	registry := rpc.NewRegistry()
	Register(registry, Config{})

	for _, name := range accountMethodNames {
		if !slices.Contains(registry.Names(), name) {
			t.Errorf("method %s is not registered", name)
		}
	}
}

func TestAccountMethodsRejectInvalidUsernames(t *testing.T) {
	invalid := []any{"", "ab", "Budi", "1budi", "budi-s", "budi;id", "../etc", "root", "rayxo", "www-data", "mysql", 42, nil}

	for _, method := range accountMethodNames {
		for _, username := range invalid {
			system := newFakeSystem()

			_, err := callAccount(t, system, method, map[string]any{"username": username})

			assertCode(t, err, rpc.CodeInvalidParams)

			if len(system.calls) != 0 {
				t.Fatalf("%s(%v) reached the system: %v", method, username, system.calls)
			}
		}
	}
}

func TestAccountMethodsRejectUnknownParams(t *testing.T) {
	for _, method := range accountMethodNames {
		system := newFakeSystem()

		_, err := callAccount(t, system, method, map[string]any{"username": "budi", "shell": "/bin/bash"})

		assertCode(t, err, rpc.CodeInvalidParams)

		if len(system.calls) != 0 {
			t.Fatalf("%s reached the system: %v", method, system.calls)
		}
	}
}

func TestAccountMethodsLockTheAccount(t *testing.T) {
	params := map[string]string{"account.set_quota": `{"username":"budi","disk_mb":1024,"inodes":0}`}

	system := newFakeSystem()
	for name, method := range accountMethods(system, system, system.quotaManager(), system.databases(), system, system.limits()) {
		if name == "quota.report" {
			if method.LockKey != nil {
				t.Fatal("quota.report only reads, but locks")
			}

			continue
		}

		if method.LockKey == nil {
			t.Fatalf("%s has no lock key", name)
		}

		raw := cmp.Or(params[name], `{"username":"budi"}`)

		key, err := method.LockKey(json.RawMessage(raw))
		if err != nil || key != "account:budi" {
			t.Fatalf("%s lock key = %q, %v; want account:budi", name, key, err)
		}

		_, err = method.LockKey(json.RawMessage(strings.Replace(raw, "budi", "root", 1)))
		assertCode(t, err, rpc.CodeInvalidParams)
	}
}

func TestCreateMakesTheAccount(t *testing.T) {
	system := newFakeSystem()

	result, err := callAccount(t, system, "account.create", map[string]string{"username": "budi"})
	if err != nil {
		t.Fatalf("create failed: %v", err)
	}

	if string(result) != `{"username":"budi","uid":1001,"gid":1001,"home":"/home/budi"}` {
		t.Fatalf("unexpected result %s", result)
	}

	assertCalls(t, system, "lookup budi", "group rayxo-access www-data", "useradd budi", "lookup budi", "prepare /home/budi", "group rayxo-sftp budi")

	user := system.users["budi"]
	if user.Comment != "rayxo" || user.Shell != "/usr/sbin/nologin" || user.Home != "/home/budi" {
		t.Fatalf("user created with unexpected details: %+v", user)
	}

	access := system.groups["rayxo-access"]
	if access == nil || !slices.Contains(access.Members, "www-data") {
		t.Fatalf("www-data is not in rayxo-access: %+v", access)
	}

	want := sysuser.Home{
		Path:      "/home/budi",
		Mode:      0o710,
		UID:       1001,
		AccessGID: access.GID,
		GID:       1001,
		Dirs: []sysuser.Dir{
			{Name: "domains", Mode: 0o711},
			{Name: "tmp", Mode: 0o700},
			{Name: "backups", Mode: 0o700},
			{Name: ".trash", Mode: 0o700},
		},
	}
	if home := system.homes["/home/budi"]; !reflect.DeepEqual(home, want) {
		t.Fatalf("home prepared as %+v, want %+v", home, want)
	}
}

func TestCreateIsIdempotent(t *testing.T) {
	system := newFakeSystem()

	first, err := callAccount(t, system, "account.create", map[string]string{"username": "budi"})
	if err != nil {
		t.Fatalf("first create failed: %v", err)
	}

	system.calls = nil

	second, err := callAccount(t, system, "account.create", map[string]string{"username": "budi"})
	if err != nil {
		t.Fatalf("second create failed: %v", err)
	}

	if string(first) != string(second) {
		t.Fatalf("results differ: %s and %s", first, second)
	}

	assertCalls(t, system, "lookup budi", "group rayxo-access www-data", "prepare /home/budi", "group rayxo-sftp budi")
}

func TestCreateReportsNamesTakenByGroups(t *testing.T) {
	system := newFakeSystem()
	system.groups["budi"] = &sysuser.Group{Name: "budi", GID: 1500}

	_, err := callAccount(t, system, "account.create", map[string]string{"username": "budi"})

	agentErr := assertCode(t, err, rpc.CodeActionFailed)
	if agentErr.Message != "username budi is already used by a system account" {
		t.Fatalf("unexpected message %q", agentErr.Message)
	}
}

func TestForeignAccountsAreNeverChanged(t *testing.T) {
	foreign := map[string]sysuser.User{
		"no marker":       {Name: "budi", UID: 1001, GID: 1001, Comment: "Budi Santoso", Home: "/home/budi"},
		"empty comment":   {Name: "budi", UID: 1001, GID: 1001, Home: "/home/budi"},
		"marker later":    {Name: "budi", UID: 1001, GID: 1001, Comment: "Budi,rayxo", Home: "/home/budi"},
		"system uid":      {Name: "budi", UID: 999, GID: 999, Comment: "rayxo", Home: "/home/budi"},
		"root uid":        {Name: "budi", UID: 0, GID: 0, Comment: "rayxo", Home: "/home/budi"},
		"home elsewhere":  {Name: "budi", UID: 1001, GID: 1001, Comment: "rayxo", Home: "/var/www"},
		"home in a child": {Name: "budi", UID: 1001, GID: 1001, Comment: "rayxo", Home: "/home/budi/x"},
	}

	for description, user := range foreign {
		for _, method := range accountMethodNames {
			system := newFakeSystem()
			system.users["budi"] = user

			_, err := callAccount(t, system, method, map[string]string{"username": "budi"})

			agentErr := assertCode(t, err, rpc.CodeActionFailed)
			if description != "home elsewhere" && description != "home in a child" &&
				agentErr.Message != "username budi is already used by a system account" {
				t.Fatalf("%s for %s: unexpected message %q", method, description, agentErr.Message)
			}

			assertCalls(t, system, "lookup budi")
		}
	}
}

func TestSuspendLocksTheAccount(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 1001)

	result, err := callAccount(t, system, "account.suspend", map[string]string{"username": "budi"})
	if err != nil {
		t.Fatalf("suspend failed: %v", err)
	}

	if string(result) != `{}` {
		t.Fatalf("unexpected result %s", result)
	}

	assertCalls(t, system, "lookup budi", "lock budi", "shell budi /usr/sbin/nologin", "web suspend budi", "db lock budi")
}

func TestUnsuspendUnlocksTheAccount(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 1001)

	result, err := callAccount(t, system, "account.unsuspend", map[string]string{"username": "budi"})
	if err != nil {
		t.Fatalf("unsuspend failed: %v", err)
	}

	if string(result) != `{}` {
		t.Fatalf("unexpected result %s", result)
	}

	assertCalls(t, system, "lookup budi", "unlock budi", "web unsuspend budi", "db unlock budi")
}

func TestSuspendAndUnsuspendNeedAnExistingAccount(t *testing.T) {
	for _, method := range []string{"account.suspend", "account.unsuspend"} {
		system := newFakeSystem()

		_, err := callAccount(t, system, method, map[string]string{"username": "budi"})

		agentErr := assertCode(t, err, rpc.CodeActionFailed)
		if agentErr.Message != "account budi does not exist" {
			t.Fatalf("%s: unexpected message %q", method, agentErr.Message)
		}
	}
}

func TestDeleteRemovesWebsitesKillsProcessesThenRemovesTheAccount(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 1001)

	result, err := callAccount(t, system, "account.delete", map[string]string{"username": "budi"})
	if err != nil {
		t.Fatalf("delete failed: %v", err)
	}

	if string(result) != `{}` {
		t.Fatalf("unexpected result %s", result)
	}

	assertCalls(t, system, "lookup budi", "web remove budi", "db remove budi", "unlimit budi 1001", "kill 1001",
		"quotaon -p -u /", "setquota -u -- budi 0 0 0 0 /", "crontab remove budi", "userdel budi")

	if _, exists := system.users["budi"]; exists {
		t.Fatal("the user still exists")
	}
}

func TestDeleteOfAMissingAccountSucceeds(t *testing.T) {
	system := newFakeSystem()

	result, err := callAccount(t, system, "account.delete", map[string]string{"username": "budi"})
	if err != nil {
		t.Fatalf("delete failed: %v", err)
	}

	if string(result) != `{}` {
		t.Fatalf("unexpected result %s", result)
	}

	// Web configuration and databases left behind by the account are still
	// removed.
	assertCalls(t, system, "lookup budi", "web remove budi", "db remove budi", "unlimit budi 0")
}

func TestWebFailuresOfAccountMethodsAreReadable(t *testing.T) {
	failures := map[error]string{
		webserver.ErrConfigRejected: "the web server rejected the configuration for account budi",
		errors.ErrUnsupported:       "websites can only be managed on Linux",
	}

	for _, method := range []string{"account.suspend", "account.unsuspend", "account.delete"} {
		for failure, message := range failures {
			system := newFakeSystem()
			system.addAccount("budi", 1001)
			system.webFailWith = fmt.Errorf("nginx -t: %w", failure)

			_, err := callAccount(t, system, method, map[string]string{"username": "budi"})

			agentErr := assertCode(t, err, rpc.CodeActionFailed)
			if agentErr.Message != message {
				t.Fatalf("%s: unexpected message %q", method, agentErr.Message)
			}
		}
	}

	// A failed web removal stops account.delete before the user is touched.
	system := newFakeSystem()
	system.addAccount("budi", 1001)
	system.webFailWith = errors.New("systemctl disable failed")

	_, err := callAccount(t, system, "account.delete", map[string]string{"username": "budi"})
	if err == nil {
		t.Fatal("expected an error")
	}

	if _, ok := errors.AsType[*rpc.Error](err); ok {
		t.Fatalf("system details would reach the panel: %v", err)
	}

	assertCalls(t, system, "lookup budi", "web remove budi")
}

func TestDeleteRefusesSystemUIDs(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 999)

	_, err := callAccount(t, system, "account.delete", map[string]string{"username": "budi"})

	assertCode(t, err, rpc.CodeActionFailed)
	assertCalls(t, system, "lookup budi")
}

func TestSystemFailuresStayInternal(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 1001)
	system.failWith = errors.New("/usr/sbin/userdel exited with code 12: cannot remove /home/budi")

	_, err := callAccount(t, system, "account.delete", map[string]string{"username": "budi"})
	if err == nil {
		t.Fatal("expected an error")
	}

	if _, ok := errors.AsType[*rpc.Error](err); ok {
		t.Fatalf("system details would reach the panel: %v", err)
	}
}

func TestUnsupportedPlatformsGetAReadableError(t *testing.T) {
	if runtime.GOOS == "linux" {
		t.Skip("this would change the real system")
	}

	for _, method := range accountMethodNames {
		_, err := callAccount(t, sysuser.System{}, method, map[string]string{"username": "budi"})

		agentErr := assertCode(t, err, rpc.CodeActionFailed)
		if agentErr.Message != "accounts can only be managed on Linux" {
			t.Fatalf("%s: unexpected message %q", method, agentErr.Message)
		}
	}
}

// callAccount runs method the way the server does: lock key first (when it
// has one), then the handler. The result is returned as JSON.
func callAccount(t *testing.T, system accountSystem, method string, params any) (json.RawMessage, error) {
	t.Helper()

	raw, err := json.Marshal(params)
	if err != nil {
		t.Fatal(err)
	}

	web, ok := system.(accountWeb)
	if !ok {
		web = unreachableWeb{t}
	}

	var quotas accountQuota = unreachableQuota{t}

	var databases accountDatabases = unreachableDatabases{t}

	var jails accountJails = jail.New(jail.Config{Root: t.TempDir()})

	var limits accountLimits = unreachableLimits{t}

	if fake, ok := system.(*fakeSystem); ok {
		quotas = fake.quotaManager()
		databases = fake.databases()
		jails = fake
		limits = fake.limits()
	}

	registered, ok := accountMethods(system, web, quotas, databases, jails, limits)[method]
	if !ok {
		t.Fatalf("unknown method %s", method)
	}

	if registered.LockKey != nil {
		if _, err := registered.LockKey(raw); err != nil {
			return nil, err
		}
	}

	result, err := registered.Handler(context.Background(), raw)
	if err != nil {
		return nil, err
	}

	encoded, err := json.Marshal(result)
	if err != nil {
		t.Fatalf("result cannot be encoded: %v", err)
	}

	return encoded, nil
}

func assertCode(t *testing.T, err error, code int) *rpc.Error {
	t.Helper()

	agentErr, ok := errors.AsType[*rpc.Error](err)
	if !ok {
		t.Fatalf("expected agent error %d, got %v", code, err)
	}

	if agentErr.Code != code {
		t.Fatalf("expected code %d, got %d (%s)", code, agentErr.Code, agentErr.Message)
	}

	return agentErr
}

func assertCalls(t *testing.T, system *fakeSystem, want ...string) {
	t.Helper()

	if !slices.Equal(system.calls, want) {
		t.Fatalf("system calls = %q, want %q", system.calls, want)
	}
}

// fakeSystem is an in-memory accountSystem that records every call and
// refuses what a real system would refuse.
type fakeSystem struct {
	users  map[string]sysuser.User
	groups map[string]*sysuser.Group
	homes  map[string]sysuser.Home
	calls  []string
	// crontabs are the installed crontabs by user.
	crontabs map[string]string
	// passwords are the password hashes set by user.
	passwords map[string]string
	// jails are the SSH jails by account.
	jails map[string]jail.Account

	nextID int
	// failWith, when set, is returned by every change.
	failWith error
	// webFailWith, when set, is returned by the web methods.
	webFailWith error
	// dbFailWith, when set, is returned by the database methods.
	dbFailWith error

	// quotaState is what quotaon -p reports for /, the filesystem of
	// /home: "on", "off", "none" (mounted without quota options) or
	// "missing" (the quota tools are not installed).
	quotaState string
	// repquota is the output of repquota.
	repquota string
	// quotaFailWith, when set, is returned by setquota and repquota.
	quotaFailWith error
	// quotaLogs receives what the quota manager logs.
	quotaLogs bytes.Buffer
}

func newFakeSystem() *fakeSystem {
	return &fakeSystem{
		users:      map[string]sysuser.User{},
		groups:     map[string]*sysuser.Group{},
		homes:      map[string]sysuser.Home{},
		crontabs:   map[string]string{},
		passwords:  map[string]string{},
		jails:      map[string]jail.Account{},
		nextID:     1001,
		quotaState: "on",
	}
}

// quotaManager returns a real quota manager whose programs are faked by
// runQuota, which records them as calls.
func (f *fakeSystem) quotaManager() *quota.Manager {
	return quota.New(quota.Config{
		HomeRoot:   "/home",
		MountPoint: func(string) (string, error) { return "/", nil },
		Run:        f.runQuota,
		Logger:     slog.New(slog.NewTextHandler(&f.quotaLogs, nil)),
	})
}

func (f *fakeSystem) runQuota(_ context.Context, program string, args ...string) ([]byte, error) {
	name := filepath.Base(program)
	f.calls = append(f.calls, name+" "+strings.Join(args, " "))

	switch name {
	case "quotaon":
		switch f.quotaState {
		case "on":
			// quotaon -p exits with the number of quota types that are on.
			return []byte("user quota on / (/dev/vda1) is on\n"),
				&runner.Error{Program: program, ExitCode: 1, Err: errors.New("exit status 1")}
		case "off":
			return []byte("user quota on / (/dev/vda1) is off\n"), nil
		case "none":
			return []byte("quotaon: Mountpoint (or device) / not found or has no quota enabled.\n"), nil
		case "missing":
			return nil, &runner.Error{Program: program, ExitCode: -1, Err: fmt.Errorf("fork/exec %s: %w", program, fs.ErrNotExist)}
		}
	case "setquota":
		return nil, f.quotaFailWith
	case "repquota":
		return []byte(f.repquota), f.quotaFailWith
	}

	return nil, fmt.Errorf("unexpected program %s", program)
}

// addAccount adds a user that looks like one account.create made.
func (f *fakeSystem) addAccount(name string, uid int) {
	f.users[name] = sysuser.User{Name: name, UID: uid, GID: uid, Comment: "rayxo", Home: "/home/" + name, Shell: "/usr/sbin/nologin"}
	f.groups[name] = &sysuser.Group{Name: name, GID: uid}
}

func (f *fakeSystem) record(format string, args ...any) error {
	f.calls = append(f.calls, fmt.Sprintf(format, args...))

	return f.failWith
}

func (f *fakeSystem) LookupUser(_ context.Context, name string) (sysuser.User, error) {
	f.calls = append(f.calls, "lookup "+name)

	user, ok := f.users[name]
	if !ok {
		return sysuser.User{}, fmt.Errorf("user %s: %w", name, sysuser.ErrNotFound)
	}

	return user, nil
}

func (f *fakeSystem) EnsureGroupMember(_ context.Context, group, member string) (int, error) {
	if err := f.record("group %s %s", group, member); err != nil {
		return 0, err
	}

	found, ok := f.groups[group]
	if !ok {
		found = &sysuser.Group{Name: group, GID: 990}
		f.groups[group] = found
	}

	if !slices.Contains(found.Members, member) {
		found.Members = append(found.Members, member)
	}

	return found.GID, nil
}

func (f *fakeSystem) RemoveGroupMember(_ context.Context, group, member string) error {
	if err := f.record("ungroup %s %s", group, member); err != nil {
		return err
	}

	if found, ok := f.groups[group]; ok {
		found.Members = slices.DeleteFunc(found.Members, func(name string) bool { return name == member })
	}

	return nil
}

// Enable, Disable and Exists make fakeSystem the accounts' jails too.
func (f *fakeSystem) Enable(account jail.Account) error {
	if err := f.record("jail %s", account.Name); err != nil {
		return err
	}

	f.jails[account.Name] = account

	return nil
}

func (f *fakeSystem) Disable(name string) error {
	if err := f.record("unjail %s", name); err != nil {
		return err
	}

	delete(f.jails, name)

	return nil
}

func (f *fakeSystem) Exists(name string) bool {
	_, ok := f.jails[name]

	return ok
}

func (f *fakeSystem) AddUser(_ context.Context, user sysuser.NewUser) error {
	if err := f.record("useradd %s", user.Name); err != nil {
		return err
	}

	if _, exists := f.users[user.Name]; exists {
		return sysuser.ErrNameTaken
	}

	if _, exists := f.groups[user.Name]; exists {
		return sysuser.ErrNameTaken
	}

	id := f.nextID
	f.nextID++

	f.users[user.Name] = sysuser.User{Name: user.Name, UID: id, GID: id, Comment: user.Comment, Home: user.Home, Shell: user.Shell}
	f.groups[user.Name] = &sysuser.Group{Name: user.Name, GID: id}

	return nil
}

func (f *fakeSystem) SetPasswordLock(_ context.Context, name string, locked bool) error {
	action := "unlock"
	if locked {
		action = "lock"
	}

	return f.record("%s %s", action, name)
}

func (f *fakeSystem) SetShell(_ context.Context, name, shell string) error {
	if err := f.record("shell %s %s", name, shell); err != nil {
		return err
	}

	user := f.users[name]
	user.Shell = shell
	f.users[name] = user

	return nil
}

func (f *fakeSystem) ListUsers(context.Context) ([]sysuser.User, error) {
	f.calls = append(f.calls, "list users")

	users := slices.Collect(maps.Values(f.users))
	slices.SortFunc(users, func(a, b sysuser.User) int { return cmp.Compare(a.UID, b.UID) })

	return users, nil
}

func (f *fakeSystem) KillProcesses(_ context.Context, uid int) error {
	return f.record("kill %d", uid)
}

func (f *fakeSystem) DeleteUser(_ context.Context, name string) error {
	if err := f.record("userdel %s", name); err != nil {
		return err
	}

	delete(f.users, name)
	delete(f.groups, name)

	return nil
}

func (f *fakeSystem) SetPassword(_ context.Context, name, hash string) error {
	f.passwords[name] = hash

	return f.record("password %s", name)
}

func (f *fakeSystem) SetCrontab(_ context.Context, name string, crontab []byte) error {
	if len(crontab) == 0 {
		delete(f.crontabs, name)

		return f.record("crontab remove %s", name)
	}

	f.crontabs[name] = string(crontab)

	return f.record("crontab %s", name)
}

func (f *fakeSystem) PrepareHome(_ context.Context, home sysuser.Home) error {
	if err := f.record("prepare %s", home.Path); err != nil {
		return err
	}

	f.homes[home.Path] = home

	return nil
}

func (f *fakeSystem) recordWeb(format string, args ...any) error {
	if err := f.record(format, args...); err != nil {
		return err
	}

	return f.webFailWith
}

func (f *fakeSystem) SuspendAccount(_ context.Context, user string) error {
	return f.recordWeb("web suspend %s", user)
}

func (f *fakeSystem) UnsuspendAccount(_ context.Context, user string) error {
	return f.recordWeb("web unsuspend %s", user)
}

func (f *fakeSystem) RemoveAccount(_ context.Context, user string) error {
	return f.recordWeb("web remove %s", user)
}

// fakeDatabases records the account methods' calls to MariaDB with the
// other calls of its system.
// fakeLimits records the resource limits of accounts as calls.
type fakeLimits struct {
	f *fakeSystem
}

func (f *fakeSystem) limits() fakeLimits {
	return fakeLimits{f: f}
}

func (l fakeLimits) Apply(_ context.Context, user string, uid int, limits cgroup.Limits) error {
	return l.f.record("limits %s %d cpu=%d mem=%d tasks=%d io=%d", user, uid, limits.CPUPercent, limits.MemoryMB, limits.Tasks, limits.IOWeight)
}

func (l fakeLimits) Remove(_ context.Context, user string, uid int) error {
	return l.f.record("unlimit %s %d", user, uid)
}

// unreachableLimits fails the test when an account method gets as far as
// the resource limits, for systems that must fail before that.
type unreachableLimits struct {
	t *testing.T
}

func (u unreachableLimits) Apply(_ context.Context, user string, _ int, _ cgroup.Limits) error {
	u.t.Errorf("the resource limits were reached for %s", user)

	return errors.New("unreachable")
}

func (u unreachableLimits) Remove(_ context.Context, user string, _ int) error {
	u.t.Errorf("the resource limits were reached for %s", user)

	return errors.New("unreachable")
}

type fakeDatabases struct {
	f *fakeSystem
}

func (f *fakeSystem) databases() fakeDatabases {
	return fakeDatabases{f: f}
}

func (d fakeDatabases) record(format string, args ...any) error {
	if err := d.f.record(format, args...); err != nil {
		return err
	}

	return d.f.dbFailWith
}

func (d fakeDatabases) RemoveAccount(_ context.Context, user string) error {
	return d.record("db remove %s", user)
}

func (d fakeDatabases) LockAccount(_ context.Context, user string, lock bool) error {
	if lock {
		return d.record("db lock %s", user)
	}

	return d.record("db unlock %s", user)
}

// unreachableDatabases fails the test when an account method gets as far
// as MariaDB, for systems that must fail before that.
type unreachableDatabases struct {
	t *testing.T
}

func (u unreachableDatabases) RemoveAccount(_ context.Context, user string) error {
	u.t.Errorf("MariaDB was reached for %s", user)

	return errors.New("unreachable")
}

func (u unreachableDatabases) LockAccount(_ context.Context, user string, _ bool) error {
	u.t.Errorf("MariaDB was reached for %s", user)

	return errors.New("unreachable")
}

// unreachableWeb fails the test when an account method gets as far as the
// web server, for systems that must fail before that.
type unreachableWeb struct {
	t *testing.T
}

func (u unreachableWeb) fail(user string) error {
	u.t.Helper()
	u.t.Errorf("the web server was reached for %s", user)

	return errors.New("unreachable")
}

func (u unreachableWeb) SuspendAccount(_ context.Context, user string) error   { return u.fail(user) }
func (u unreachableWeb) UnsuspendAccount(_ context.Context, user string) error { return u.fail(user) }
func (u unreachableWeb) RemoveAccount(_ context.Context, user string) error    { return u.fail(user) }

// unreachableQuota fails the test when an account method gets as far as the
// disk quotas, for systems that must fail before that.
type unreachableQuota struct {
	t *testing.T
}

func (u unreachableQuota) SetUserLimits(_ context.Context, user string, _ quota.Limits) (bool, error) {
	u.t.Helper()
	u.t.Errorf("the quotas were reached for %s", user)

	return false, errors.New("unreachable")
}

func (u unreachableQuota) ClearUserLimits(_ context.Context, user string) {
	u.t.Helper()
	u.t.Errorf("the quotas were reached for %s", user)
}

func (u unreachableQuota) Report(context.Context) (quota.Report, error) {
	u.t.Helper()
	u.t.Error("the quotas were reached")

	return quota.Report{}, errors.New("unreachable")
}

func TestDeleteKeepsTheAccountWhenItsDatabasesCannotBeDropped(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 1001)
	system.dbFailWith = fmt.Errorf("drop: %w", database.ErrServer)

	_, err := callAccount(t, system, "account.delete", map[string]string{"username": "budi"})

	agentErr := assertCode(t, err, rpc.CodeActionFailed)
	if agentErr.Message != "MariaDB could not delete the databases of budi; the agent log has the details" {
		t.Fatalf("unexpected message %q", agentErr.Message)
	}

	// The user stays, so a new account with the same name cannot inherit
	// the databases; deleting again retries.
	if _, exists := system.users["budi"]; !exists {
		t.Fatal("the user was deleted")
	}

	assertCalls(t, system, "lookup budi", "web remove budi", "db remove budi")
}

// sha512Hash is crypt("test-password") with SHA512-CRYPT.
const sha512Hash = "$6$abcdefgh12345678$6tQGsA.SOj0o1.rHXHtYJZ5n8sL8YS0OTe6xNGJMdHyscxU5wQGoAWDHCkubqoooNxoSq4iQvCR9KdePjmPVF/"

func TestSetPasswordSetsTheHashAndJoinsTheSFTPGroup(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 1001)

	if _, err := callAccount(t, system, "account.set_password", map[string]string{"username": "budi", "password_hash": sha512Hash}); err != nil {
		t.Fatal(err)
	}

	if system.passwords["budi"] != sha512Hash {
		t.Fatalf("hash = %q", system.passwords["budi"])
	}

	assertCalls(t, system, "lookup budi", "group rayxo-sftp budi", "password budi")
}

func TestSetPasswordWithoutAHashLocksThePassword(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 1001)

	if _, err := callAccount(t, system, "account.set_password", map[string]string{"username": "budi", "password_hash": ""}); err != nil {
		t.Fatal(err)
	}

	if system.passwords["budi"] != "!" {
		t.Fatalf("hash = %q", system.passwords["budi"])
	}
}

func TestSetPasswordRefusesWhatIsNotAHashOrAnAccount(t *testing.T) {
	invalid := []map[string]string{
		{"username": "budi", "password_hash": "secret-password"},
		{"username": "budi", "password_hash": "!"},
		{"username": "budi", "password_hash": "$1$abc$def"},
		{"username": "budi", "password_hash": sha512Hash + "\nroot:x"},
		{"username": "budi", "password_hash": sha512Hash + ":0"},
		{"username": "root", "password_hash": sha512Hash},
		{"username": "budi", "password": "secret-password"},
	}

	for _, params := range invalid {
		system := newFakeSystem()
		system.addAccount("budi", 1001)

		_, err := callAccount(t, system, "account.set_password", params)
		assertCode(t, err, rpc.CodeInvalidParams)

		if len(system.passwords) != 0 {
			t.Fatalf("%v reached the system", params)
		}
	}

	system := newFakeSystem()

	_, err := callAccount(t, system, "account.set_password", map[string]string{"username": "budi", "password_hash": sha512Hash})
	if agentErr := assertCode(t, err, rpc.CodeActionFailed); agentErr.Message != "account budi does not exist" {
		t.Fatalf("message = %q", agentErr.Message)
	}
}
