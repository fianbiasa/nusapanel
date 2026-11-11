package mail

import (
	"context"
	"slices"
	"strings"
	"testing"
)

// doveadm -f tab quota get -A on a server: one mailbox with a quota, one
// without.
const quotaReport = "Username\tQuota name\tType\tValue\tLimit\t%\n" +
	"hi@example.com\tUser quota\tSTORAGE\t9\t20480\t0\n" +
	"hi@example.com\tUser quota\tMESSAGE\t2\t-\t0\n" +
	"info@example.net\tUser quota\tSTORAGE\t0\t-\t0\n" +
	"info@example.net\tUser quota\tMESSAGE\t0\t-\t0\n"

func TestUsageReadsDovecotsQuotaReport(t *testing.T) {
	var calls []string

	manager := New(Config{Run: func(_ context.Context, program string, args ...string) ([]byte, error) {
		calls = append(calls, program+" "+strings.Join(args, " "))

		return []byte(quotaReport), nil
	}})

	usages, err := manager.Usage(context.Background())
	if err != nil {
		t.Fatal(err)
	}

	want := []MailboxUsage{
		{Address: "hi@example.com", UsedKB: 9, Messages: 2},
		{Address: "info@example.net", UsedKB: 0, Messages: 0},
	}
	if !slices.Equal(usages, want) {
		t.Fatalf("usages: %+v", usages)
	}

	if !slices.Equal(calls, []string{"/usr/bin/doveadm -f tab quota get -A"}) {
		t.Fatalf("calls: %v", calls)
	}
}

func TestAnUnreadableQuotaReportIsAnError(t *testing.T) {
	if _, err := parseQuota([]byte("hi@example.com\tUser quota\tSTORAGE\tlots\t-\t0\n")); err == nil {
		t.Fatal("an unreadable value was accepted")
	}

	usages, err := parseQuota(nil)
	if err != nil || len(usages) != 0 {
		t.Fatalf("an empty report: %v %v", usages, err)
	}
}
