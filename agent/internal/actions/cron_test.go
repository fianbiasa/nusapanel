package actions

import (
	"context"
	"encoding/json"
	"slices"
	"strings"
	"testing"

	"rayxo/agent/internal/rpc"
)

func callCron(t *testing.T, system *fakeSystem, params any) error {
	t.Helper()

	raw, err := json.Marshal(params)
	if err != nil {
		t.Fatal(err)
	}

	method := cronMethods(system)["cron.apply"]

	key, err := method.LockKey(raw)
	if err != nil {
		return err
	}

	if key != "account:budi" {
		t.Fatalf("cron.apply locks %q", key)
	}

	_, err = method.Handler(context.Background(), raw)

	return err
}

func TestCronApplyIsRegistered(t *testing.T) {
	registry := rpc.NewRegistry()
	Register(registry, Config{})

	if !slices.Contains(registry.Names(), "cron.apply") {
		t.Fatal("cron.apply is not registered")
	}
}

func TestCronApplyInstallsTheJobs(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 1001)

	err := callCron(t, system, map[string]any{"username": "budi", "jobs": []map[string]string{
		{"schedule": "*/5 * * * *", "command": "php artisan schedule:run"},
		{"schedule": "@daily", "command": "date +%F >> run.log"},
	}})
	if err != nil {
		t.Fatal(err)
	}

	crontab := system.crontabs["budi"]
	for _, line := range []string{"MAILTO=\"\"\n", "\n*/5 * * * * php artisan schedule:run\n", "\n@daily date +\\%F >> run.log\n"} {
		if !strings.Contains(crontab, line) {
			t.Errorf("crontab lacks %q:\n%s", line, crontab)
		}
	}
}

func TestCronApplyMailsTheOutputToTheAccountsAddress(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 1001)

	err := callCron(t, system, map[string]any{"username": "budi", "mail_to": "budi@example.com", "jobs": []map[string]string{
		{"schedule": "@daily", "command": "php backup.php"},
	}})
	if err != nil {
		t.Fatal(err)
	}

	if crontab := system.crontabs["budi"]; !strings.Contains(crontab, "\nMAILTO=\"budi@example.com\"\n") {
		t.Fatalf("crontab does not mail its output:\n%s", crontab)
	}

	for _, address := range []string{"budi@example.com\nMAILTO=evil@example.com", "-oQ/tmp@example.com", "budi"} {
		err := callCron(t, system, map[string]any{"username": "budi", "mail_to": address, "jobs": []any{}})
		assertCode(t, err, rpc.CodeInvalidParams)
	}
}

func TestCronApplyWithoutJobsRemovesTheCrontab(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 1001)
	system.crontabs["budi"] = "old"

	if err := callCron(t, system, map[string]any{"username": "budi", "jobs": []any{}}); err != nil {
		t.Fatal(err)
	}

	if _, ok := system.crontabs["budi"]; ok || !slices.Contains(system.calls, "crontab remove budi") {
		t.Fatalf("crontab not removed: %q", system.calls)
	}
}

func TestCronApplyNeedsTheAccount(t *testing.T) {
	system := newFakeSystem()

	err := callCron(t, system, map[string]any{"username": "budi", "jobs": []any{}})
	if agentErr := assertCode(t, err, rpc.CodeActionFailed); agentErr.Message != "account budi does not exist" {
		t.Fatalf("message = %q", agentErr.Message)
	}
}

func TestCronApplyRefusesJobsThatCouldChangeTheCrontab(t *testing.T) {
	invalid := []any{
		[]map[string]string{{"schedule": "* * * * *", "command": "true\nMAILTO=evil@example.com"}},
		[]map[string]string{{"schedule": "* * * * *\n* * * * * evil", "command": "true"}},
		[]map[string]string{{"schedule": "@reboot", "command": "true"}},
		[]map[string]string{{"schedule": "* * * * *", "command": ""}},
		[]map[string]string{{"schedule": "* * * * *", "command": "true", "user": "root"}},
	}

	for _, jobs := range invalid {
		system := newFakeSystem()
		system.addAccount("budi", 1001)

		err := callCron(t, system, map[string]any{"username": "budi", "jobs": jobs})
		assertCode(t, err, rpc.CodeInvalidParams)

		if len(system.crontabs) != 0 {
			t.Fatalf("%v reached the crontab", jobs)
		}
	}
}
