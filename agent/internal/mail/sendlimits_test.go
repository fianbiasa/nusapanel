package mail

import (
	"os"
	"testing"
)

func writeMailname(t *testing.T, cfg Config, name string) {
	t.Helper()

	if err := os.WriteFile(cfg.MailnamePath, []byte(name+"\n"), 0o644); err != nil {
		t.Fatal(err)
	}
}

func TestApplySendLimitsWritesWhoseMailIsWhose(t *testing.T) {
	manager, system, cfg := newManager(t)
	writeMailname(t, cfg, "server.example.net")

	limits := SendLimits{
		AccountLimit: 300,
		Accounts: []SendLimitAccount{
			{Username: "budi", Domains: []string{"example.com", "blog.example.com"}},
			{Username: "sari", Domains: []string{"sari.example"}},
		},
	}

	if err := manager.ApplySendLimits(limits); err != nil {
		t.Fatal(err)
	}

	content, err := os.ReadFile(cfg.SendLimitMap)
	if err != nil {
		t.Fatal(err)
	}

	want := "# Written by rayxod: the daily send limits (recipients per 24 hours) and whose mail is whose.\n" +
		"account 300\n" +
		"sender:@blog.example.com budi\n" +
		"sender:@example.com budi\n" +
		"sender:@sari.example sari\n" +
		"sender:budi@server.example.net budi\n" +
		"sender:sari@server.example.net sari\n"
	if string(content) != want {
		t.Fatalf("map:\n%s\nwant:\n%s", content, want)
	}

	info, err := os.Stat(cfg.SendLimitMap)
	if err != nil {
		t.Fatal(err)
	}

	if info.Mode().Perm() != 0o640 || len(system.chowns) != 1 {
		t.Fatalf("mode %v, chowns %v: only Rspamd's group may read the map", info.Mode().Perm(), system.chowns)
	}

	// The same limits again leave the file alone.
	if err := manager.ApplySendLimits(limits); err != nil {
		t.Fatal(err)
	}

	if len(system.chowns) != 1 {
		t.Fatalf("an unchanged map was written again: %v", system.chowns)
	}
}

func TestNoLimitLeavesOnlyWhoseMailIsWhose(t *testing.T) {
	manager, _, cfg := newManager(t)
	writeMailname(t, cfg, "server.example.net")

	if err := manager.ApplySendLimits(SendLimits{ServerLimit: 5000}); err != nil {
		t.Fatal(err)
	}

	content, err := os.ReadFile(cfg.SendLimitMap)
	if err != nil {
		t.Fatal(err)
	}

	want := "# Written by rayxod: the daily send limits (recipients per 24 hours) and whose mail is whose.\nserver 5000\n"
	if string(content) != want {
		t.Fatalf("map:\n%s", content)
	}
}

func TestBadSendLimitsAreRefused(t *testing.T) {
	manager, system, cfg := newManager(t)
	writeMailname(t, cfg, "server.example.net")

	refused := map[string]SendLimits{
		"negative limit":      {AccountLimit: -1},
		"limit too large":     {ServerLimit: MaxSendLimit + 1},
		"system user":         {Accounts: []SendLimitAccount{{Username: "root"}}},
		"username with space": {Accounts: []SendLimitAccount{{Username: "budi x"}}},
		"bad domain":          {Accounts: []SendLimitAccount{{Username: "budi", Domains: []string{"example.com\nserver 1"}}}},
		"account twice":       {Accounts: []SendLimitAccount{{Username: "budi"}, {Username: "budi"}}},
		"domain twice": {Accounts: []SendLimitAccount{
			{Username: "budi", Domains: []string{"example.com"}},
			{Username: "sari", Domains: []string{"example.com"}},
		}},
	}

	for name, limits := range refused {
		if err := manager.ApplySendLimits(limits); err == nil {
			t.Errorf("%s was accepted", name)
		}
	}

	writeMailname(t, cfg, "not a name")

	if err := manager.ApplySendLimits(SendLimits{AccountLimit: 300}); err == nil {
		t.Error("a bad mail name was accepted")
	}

	if _, err := os.Stat(cfg.SendLimitMap); !os.IsNotExist(err) || len(system.chowns) != 0 {
		t.Fatalf("refused limits reached the map: %v", err)
	}
}
