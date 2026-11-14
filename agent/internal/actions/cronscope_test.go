package actions

import (
	"context"
	"errors"
	"strings"
	"testing"

	"rayxo/agent/internal/sysuser"
)

func cronScopeEnv(env map[string]string, attachErr error) (cronScope, *[]string, *strings.Builder) {
	var attached []string

	errs := &strings.Builder{}
	accounts := map[string]sysuser.User{
		"budi":  {Name: "budi", UID: 1001, Home: "/home/budi", Comment: accountMarker + ",Budi"},
		"rayxo": {Name: "rayxo", UID: 998, Home: "/var/lib/rayxo", Comment: "RAYXO panel"},
		"root":  {Name: "root", UID: 0, Home: "/root"},
	}

	return cronScope{
		env:  func(key string) string { return env[key] },
		ppid: 4242,
		lookup: func(_ context.Context, name string) (sysuser.User, error) {
			user, ok := accounts[name]
			if !ok {
				return sysuser.User{}, errors.New("no such user")
			}

			return user, nil
		},
		attach: func(_ context.Context, user string, pid int) error {
			attached = append(attached, user)

			if pid != 4242 {
				return errors.New("wrong pid")
			}

			return attachErr
		},
		errors: errs,
	}, &attached, errs
}

func TestCronJobsOfAccountsGoIntoTheirSlice(t *testing.T) {
	hook, attached, _ := cronScopeEnv(map[string]string{"PAM_TYPE": "open_session", "PAM_SERVICE": "cron", "PAM_USER": "budi"}, nil)

	if status := hook.run(); status != 0 || len(*attached) != 1 || (*attached)[0] != "budi" {
		t.Errorf("status = %d, attached = %v", status, *attached)
	}
}

func TestOtherSessionsAndUsersAreLeftAlone(t *testing.T) {
	for _, env := range []map[string]string{
		{"PAM_TYPE": "close_session", "PAM_SERVICE": "cron", "PAM_USER": "budi"},
		{"PAM_TYPE": "open_session", "PAM_SERVICE": "sshd", "PAM_USER": "budi"},
		{"PAM_TYPE": "open_session", "PAM_SERVICE": "cron", "PAM_USER": "root"},
		{"PAM_TYPE": "open_session", "PAM_SERVICE": "cron", "PAM_USER": "rayxo"},
		{"PAM_TYPE": "open_session", "PAM_SERVICE": "cron", "PAM_USER": "ghost"},
	} {
		hook, attached, _ := cronScopeEnv(env, nil)

		if status := hook.run(); status != 0 || len(*attached) != 0 {
			t.Errorf("%v: status = %d, attached = %v", env, status, *attached)
		}
	}
}

func TestAJobThatCannotMoveIsReportedButRuns(t *testing.T) {
	hook, _, errs := cronScopeEnv(map[string]string{"PAM_TYPE": "open_session", "PAM_SERVICE": "cron", "PAM_USER": "budi"}, errors.New("systemd said no"))

	if status := hook.run(); status != 1 || !strings.Contains(errs.String(), "cron job of budi: systemd said no") {
		t.Errorf("status = %d, errors = %q", status, errs.String())
	}
}
