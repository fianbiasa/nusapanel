package actions

import (
	"errors"
	"runtime"
	"slices"
	"strings"
	"testing"

	"nusapanel/agent/internal/rpc"
	"nusapanel/agent/internal/sysuser"
)

// quotaReportOutput is what `repquota -u -v -n -O csv /` prints with quota
// 4.06 for the accounts of newQuotaSystem.
const quotaReportOutput = `User,BlockStatus,FileStatus,BlockUsed,BlockSoftLimit,BlockHardLimit,BlockGrace,FileUsed,FileSoftLimit,FileHardLimit,FileGrace
#0,ok,ok,2349060,0,0,,96214,0,0,
#999,ok,ok,40,0,0,,10,0,0,
#1000,ok,ok,5120,0,0,,120,0,0,
#1001,ok,ok,1234,1048576,1048576,,56,50000,50000,
#1003,hard,ok,2097156,2097152,2097152,none,800,0,0,
`

// newQuotaSystem has the accounts budi, sari and tono, plus users that
// nusapanel does not manage.
func newQuotaSystem() *fakeSystem {
	system := newFakeSystem()
	system.addAccount("budi", 1001)
	system.addAccount("sari", 1002)
	system.addAccount("tono", 1003)
	system.users["root"] = sysuser.User{Name: "root", UID: 0, GID: 0, Comment: "root", Home: "/root", Shell: "/bin/bash"}
	system.users["ubuntu"] = sysuser.User{Name: "ubuntu", UID: 1000, GID: 1000, Comment: "Ubuntu", Home: "/home/ubuntu", Shell: "/bin/bash"}
	system.users["svc"] = sysuser.User{Name: "svc", UID: 999, GID: 999, Comment: "nusapanel", Home: "/home/svc", Shell: "/bin/sh"}
	system.users["moved"] = sysuser.User{Name: "moved", UID: 1004, GID: 1004, Comment: "nusapanel", Home: "/srv/moved", Shell: "/usr/sbin/nologin"}
	system.repquota = quotaReportOutput

	return system
}

func TestQuotaMethodsAreRegistered(t *testing.T) {
	registry := rpc.NewRegistry()
	Register(registry)

	for _, name := range []string{"account.set_quota", "quota.report"} {
		if !slices.Contains(registry.Names(), name) {
			t.Errorf("method %s is not registered", name)
		}
	}
}

func TestSetQuotaLimitsTheAccountInKiBWithoutGrace(t *testing.T) {
	cases := []struct {
		diskMB, inodes int64
		want           string
	}{
		{1024, 50000, "setquota -u -- budi 1048576 1048576 50000 50000 /"},
		{1, 0, "setquota -u -- budi 1024 1024 0 0 /"},
		{0, 0, "setquota -u -- budi 0 0 0 0 /"},
		{maxDiskMB, 1, "setquota -u -- budi 9007199254739968 9007199254739968 1 1 /"},
	}

	for _, test := range cases {
		system := newFakeSystem()
		system.addAccount("budi", 1001)

		result, err := callAccount(t, system, "account.set_quota",
			map[string]any{"username": "budi", "disk_mb": test.diskMB, "inodes": test.inodes})
		if err != nil {
			t.Fatalf("set_quota %d MB failed: %v", test.diskMB, err)
		}

		if string(result) != `{"applied":true}` {
			t.Fatalf("unexpected result %s", result)
		}

		assertCalls(t, system, "lookup budi", "quotaon -p -u /", test.want)
	}
}

func TestSetQuotaRejectsInvalidParams(t *testing.T) {
	invalid := []string{
		`{"username":"budi","inodes":0}`,
		`{"username":"budi","disk_mb":1024}`,
		`{"username":"budi","disk_mb":null,"inodes":0}`,
		`{"username":"budi","disk_mb":1024,"inodes":null}`,
		`{"username":"budi","disk_mb":-1,"inodes":0}`,
		`{"username":"budi","disk_mb":1024,"inodes":-1}`,
		`{"username":"budi","disk_mb":8796093022208,"inodes":0}`,
		`{"username":"budi","disk_mb":9223372036854775807,"inodes":0}`,
		`{"username":"budi","disk_mb":9223372036854775808,"inodes":0}`,
		`{"username":"budi","disk_mb":1024,"inodes":9223372036854775807}`,
		`{"username":"budi","disk_mb":1.5,"inodes":0}`,
		`{"username":"budi","disk_mb":"1024","inodes":0}`,
		`{"username":"budi","disk_mb":1024,"inodes":0,"grace":0}`,
		`{"username":"root","disk_mb":1024,"inodes":0}`,
		`{"username":"Budi","disk_mb":1024,"inodes":0}`,
		`{"disk_mb":1024,"inodes":0}`,
		`[]`,
	}

	for _, params := range invalid {
		system := newFakeSystem()
		system.addAccount("budi", 1001)

		_, err := callAccount(t, system, "account.set_quota", rawParams(params))

		assertCode(t, err, rpc.CodeInvalidParams)
		assertCalls(t, system)
	}
}

func TestSetQuotaNeedsAManagedAccount(t *testing.T) {
	params := map[string]any{"username": "budi", "disk_mb": 1024, "inodes": 0}

	missing := newFakeSystem()

	_, err := callAccount(t, missing, "account.set_quota", params)

	agentErr := assertCode(t, err, rpc.CodeActionFailed)
	if agentErr.Message != "account budi does not exist" {
		t.Fatalf("unexpected message %q", agentErr.Message)
	}

	assertCalls(t, missing, "lookup budi")

	for _, user := range []sysuser.User{
		{Name: "budi", UID: 1001, GID: 1001, Comment: "Budi Santoso", Home: "/home/budi"},
		{Name: "budi", UID: 999, GID: 999, Comment: "nusapanel", Home: "/home/budi"},
		{Name: "budi", UID: 1001, GID: 1001, Comment: "nusapanel", Home: "/var/www"},
	} {
		system := newFakeSystem()
		system.users["budi"] = user

		_, err := callAccount(t, system, "account.set_quota", params)

		assertCode(t, err, rpc.CodeActionFailed)
		assertCalls(t, system, "lookup budi")
	}
}

func TestSetQuotaIsNotAppliedWhenQuotasAreOff(t *testing.T) {
	for _, state := range []string{"off", "none", "missing"} {
		system := newFakeSystem()
		system.addAccount("budi", 1001)
		system.quotaState = state

		result, err := callAccount(t, system, "account.set_quota", map[string]any{"username": "budi", "disk_mb": 1024, "inodes": 0})
		if err != nil {
			t.Fatalf("%s: set_quota failed: %v", state, err)
		}

		if string(result) != `{"applied":false}` {
			t.Fatalf("%s: unexpected result %s", state, result)
		}

		assertCalls(t, system, "lookup budi", "quotaon -p -u /")

		if !strings.Contains(system.quotaLogs.String(), "disk quota not applied") {
			t.Fatalf("%s: the reason was not logged: %s", state, system.quotaLogs.String())
		}
	}
}

func TestSetQuotaFailuresStayInternal(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 1001)
	system.quotaFailWith = errors.New("/usr/sbin/setquota exited with code 1: setquota: Cannot set quota")

	_, err := callAccount(t, system, "account.set_quota", map[string]any{"username": "budi", "disk_mb": 1024, "inodes": 0})
	if err == nil {
		t.Fatal("expected an error")
	}

	if _, ok := errors.AsType[*rpc.Error](err); ok {
		t.Fatalf("system details would reach the panel: %v", err)
	}

	unknown := newFakeSystem()
	unknown.addAccount("budi", 1001)
	unknown.quotaState = "garbled"

	_, err = callAccount(t, unknown, "account.set_quota", map[string]any{"username": "budi", "disk_mb": 1024, "inodes": 0})
	if err == nil {
		t.Fatal("an unknown quota state was not an error")
	}

	if _, ok := errors.AsType[*rpc.Error](err); ok {
		t.Fatalf("system details would reach the panel: %v", err)
	}
}

func TestSetQuotaOnUnsupportedPlatformsGetsAReadableError(t *testing.T) {
	if runtime.GOOS == "linux" {
		t.Skip("this would read the real system")
	}

	_, err := callAccount(t, sysuser.System{}, "account.set_quota", map[string]any{"username": "budi", "disk_mb": 1024, "inodes": 0})

	agentErr := assertCode(t, err, rpc.CodeActionFailed)
	if agentErr.Message != "accounts can only be managed on Linux" {
		t.Fatalf("unexpected message %q", agentErr.Message)
	}
}

func TestQuotaReportListsManagedAccounts(t *testing.T) {
	system := newQuotaSystem()

	result, err := callAccount(t, system, "quota.report", map[string]any{})
	if err != nil {
		t.Fatalf("report failed: %v", err)
	}

	want := `{"enabled":true,"filesystem":"/","accounts":[` +
		`{"username":"budi","uid":1001,"disk_kb":1234,"inodes":56,"disk_limit_kb":1048576,"inode_limit":50000},` +
		`{"username":"sari","uid":1002,"disk_kb":0,"inodes":0,"disk_limit_kb":0,"inode_limit":0},` +
		`{"username":"tono","uid":1003,"disk_kb":2097156,"inodes":800,"disk_limit_kb":2097152,"inode_limit":0}]}`
	if string(result) != want {
		t.Fatalf("report = %s\nwant %s", result, want)
	}

	assertCalls(t, system, "quotaon -p -u /", "repquota -u -v -n -O csv /", "list users")
}

func TestQuotaReportWhenQuotasAreOff(t *testing.T) {
	for _, state := range []string{"off", "none", "missing"} {
		system := newQuotaSystem()
		system.quotaState = state

		result, err := callAccount(t, system, "quota.report", nil)
		if err != nil {
			t.Fatalf("%s: report failed: %v", state, err)
		}

		if string(result) != `{"enabled":false,"filesystem":"/","accounts":[]}` {
			t.Fatalf("%s: unexpected report %s", state, result)
		}

		assertCalls(t, system, "quotaon -p -u /")
	}
}

func TestQuotaReportRejectsParams(t *testing.T) {
	system := newQuotaSystem()

	_, err := callAccount(t, system, "quota.report", map[string]any{"username": "budi"})

	assertCode(t, err, rpc.CodeInvalidParams)
	assertCalls(t, system)
}

func TestQuotaReportFailuresStayInternal(t *testing.T) {
	system := newQuotaSystem()
	system.quotaFailWith = errors.New("/usr/sbin/repquota exited with code 1")

	_, err := callAccount(t, system, "quota.report", nil)
	if err == nil {
		t.Fatal("expected an error")
	}

	if _, ok := errors.AsType[*rpc.Error](err); ok {
		t.Fatalf("system details would reach the panel: %v", err)
	}
}

func TestDeleteClearsTheQuotaOnlyWhenQuotasAreOn(t *testing.T) {
	for _, state := range []string{"off", "none", "missing"} {
		system := newFakeSystem()
		system.addAccount("budi", 1001)
		system.quotaState = state

		if _, err := callAccount(t, system, "account.delete", map[string]string{"username": "budi"}); err != nil {
			t.Fatalf("%s: delete failed: %v", state, err)
		}

		assertCalls(t, system, "lookup budi", "web remove budi", "kill 1001", "quotaon -p -u /", "userdel budi")
	}
}

func TestDeleteSucceedsWhenTheQuotaCannotBeCleared(t *testing.T) {
	for _, broken := range []func(*fakeSystem){
		func(system *fakeSystem) { system.quotaFailWith = errors.New("setquota exited with code 1") },
		func(system *fakeSystem) { system.quotaState = "garbled" },
	} {
		system := newFakeSystem()
		system.addAccount("budi", 1001)
		broken(system)

		if _, err := callAccount(t, system, "account.delete", map[string]string{"username": "budi"}); err != nil {
			t.Fatalf("delete failed: %v", err)
		}

		if _, exists := system.users["budi"]; exists {
			t.Fatal("the user still exists")
		}

		if !strings.Contains(system.quotaLogs.String(), "disk quota not cleared") {
			t.Fatalf("the failure was not logged: %s", system.quotaLogs.String())
		}
	}
}

// rawParams is a params object that callAccount sends as is.
type rawParams string

func (r rawParams) MarshalJSON() ([]byte, error) {
	return []byte(r), nil
}
