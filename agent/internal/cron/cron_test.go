package cron

import (
	"strings"
	"testing"
)

func TestValidSchedules(t *testing.T) {
	valid := []string{
		"* * * * *", "*/5 * * * *", "0 3 * * *", "30 2 1 * *", "0 0 * * 0", "0 0 * * 7",
		"15,45 8-17 * * mon-fri", "0 */2 1-15/3 jan,jun sun", "59 23 31 12 6", "0-30/10 * * * *",
		"@hourly", "@daily", "@midnight", "@weekly", "@monthly", "@yearly", "@annually",
	}

	for _, schedule := range valid {
		if err := ValidateSchedule(schedule); err != nil {
			t.Errorf("%q: %v", schedule, err)
		}
	}
}

func TestInvalidSchedules(t *testing.T) {
	invalid := []string{
		"", "@reboot", "@every 5m", "* * * *", "* * * * * *", "*  * * * *", " * * * * *",
		"60 * * * *", "* 24 * * *", "* * 0 * *", "* * 32 * *", "* * * 13 *", "* * * * 8",
		"*/0 * * * *", "*/60 * * * *", "5/10 * * * *", "10-5 * * * *", "-5 * * * *", "1,,2 * * * *",
		"* * * Jan *", "* * * * MON", "* * * * monday", "007 * * * *", "+5 * * * *",
		"* * * * *\nMAILTO=x", "* * * * * ; rm", "* * * * *\t",
	}

	for _, schedule := range invalid {
		if err := ValidateSchedule(schedule); err == nil {
			t.Errorf("%q was accepted", schedule)
		}
	}
}

func TestCommandsAreSingleLines(t *testing.T) {
	if err := ValidateCommand("php /home/budi/domains/budi.test/public_html/artisan schedule:run >/dev/null 2>&1"); err != nil {
		t.Fatal(err)
	}

	for _, command := range []string{"", "   ", "echo a\nMAILTO=evil", "echo a\r", "echo \x00", strings.Repeat("a", MaxCommand+1)} {
		if ValidateCommand(command) == nil {
			t.Errorf("%q was accepted", command)
		}
	}
}

func TestValidateChecksEveryJobAndTheirNumber(t *testing.T) {
	if err := Validate([]Job{{Schedule: "* * * * *", Command: "true"}}); err != nil {
		t.Fatal(err)
	}

	if Validate([]Job{{Schedule: "* * * * *", Command: "true"}, {Schedule: "bad", Command: "true"}}) == nil {
		t.Error("a bad schedule was accepted")
	}

	if Validate(make([]Job, MaxJobs+1)) == nil {
		t.Error("too many jobs were accepted")
	}
}

func TestRender(t *testing.T) {
	got := string(Render([]Job{
		{Schedule: "*/5 * * * *", Command: "php artisan schedule:run"},
		{Schedule: "@daily", Command: `tar czf backup-$(date +%Y%m%d).tgz site && echo 100\% done`},
	}))

	want := "# Written by nusapanel from the Cron Jobs page of the panel; changes made here are replaced.\n" +
		"SHELL=/bin/bash\n" +
		"PATH=/usr/local/bin:/usr/bin:/bin\n" +
		"MAILTO=\"\"\n" +
		"\n*/5 * * * * php artisan schedule:run\n" +
		"\n@daily tar czf backup-$(date +\\%Y\\%m\\%d).tgz site && echo 100\\% done\n"

	if got != want {
		t.Fatalf("crontab =\n%s\nwant\n%s", got, want)
	}
}
