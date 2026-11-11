package mail

import (
	"context"
	"crypto/rsa"
	"crypto/x509"
	"encoding/base64"
	"errors"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"rayxo/agent/internal/files"
	"rayxo/agent/internal/sysuser"
)

type fakeSystem struct {
	paths   []sysuser.Path
	chowns  []string
	removed []string
	written map[string]string
}

func newManager(t *testing.T) (*Manager, *fakeSystem, Config) {
	t.Helper()

	base := t.TempDir()
	system := &fakeSystem{}

	cfg := Config{
		DKIMDir:      filepath.Join(base, "dkim"),
		SelectorMap:  filepath.Join(base, "rayxo", "dkim_selectors.map"),
		SendLimitMap: filepath.Join(base, "rayxo", "send_limits.map"),
		MailnamePath: filepath.Join(base, "mailname"),
		KeyGroup:     "_rspamd",
		LookupGroup: func(name string) (int, error) {
			if name != "_rspamd" {
				return 0, errors.New("unknown group")
			}

			return 4242, nil
		},
		Chown: func(path string, uid, gid int) error {
			system.chowns = append(system.chowns, filepath.Base(path))

			if uid != 0 || gid != 4242 {
				return errors.New("wrong owner")
			}

			return nil
		},
		EnsurePath: func(_ context.Context, path sysuser.Path) error {
			system.paths = append(system.paths, path)

			return nil
		},
		Remove: func(_ context.Context, account files.Account, paths []string) error {
			system.removed = append(system.removed, account.Home+": "+strings.Join(paths, ","))

			return nil
		},
		Write: func(_ context.Context, account files.Account, path, content string) error {
			if system.written == nil {
				system.written = map[string]string{}
			}

			system.written[account.Home+": "+path] = content

			return nil
		},
	}

	for _, dir := range []string{cfg.DKIMDir, filepath.Dir(cfg.SelectorMap)} {
		if err := os.MkdirAll(dir, 0o755); err != nil {
			t.Fatal(err)
		}
	}

	return New(cfg), system, cfg
}

var budi = sysuser.User{Name: "budi", UID: 1001, GID: 1001, Home: "/home/budi"}

func TestApplyDomainMakesTheFolderAndKeyOnce(t *testing.T) {
	manager, system, cfg := newManager(t)

	first, err := manager.ApplyDomain(context.Background(), budi, "example.com", "rayxo")
	if err != nil {
		t.Fatal(err)
	}

	der, err := base64.StdEncoding.DecodeString(first)
	if err != nil {
		t.Fatal(err)
	}

	public, err := x509.ParsePKIXPublicKey(der)
	if key, ok := public.(*rsa.PublicKey); err != nil || !ok || key.N.BitLen() != 2048 {
		t.Fatalf("public key = %T, %v", public, err)
	}

	want := sysuser.Path{Home: "/home/budi", Dirs: []string{"imap", "example.com"}, UID: 1001, GID: 1001, Mode: 0o700}
	if len(system.paths) != 1 || system.paths[0].Home != want.Home || !slices.Equal(system.paths[0].Dirs, want.Dirs) ||
		system.paths[0].UID != 1001 || system.paths[0].Mode != 0o700 {
		t.Fatalf("paths = %+v", system.paths)
	}

	info, err := os.Stat(filepath.Join(cfg.DKIMDir, "example.com.rayxo.key"))
	if err != nil || info.Mode().Perm() != 0o640 || len(system.chowns) != 1 {
		t.Fatalf("key file %v %v, chowns %v", info, err, system.chowns)
	}

	second, err := manager.ApplyDomain(context.Background(), budi, "example.com", "rayxo")
	if err != nil || second != first || len(system.chowns) != 1 {
		t.Fatalf("a second apply made a new key: %v %v", err, system.chowns)
	}

	content, _ := os.ReadFile(cfg.SelectorMap)
	if !strings.HasSuffix(string(content), "\nexample.com rayxo\n") {
		t.Fatalf("selector map = %q", content)
	}
}

func TestRemoveDomainForgetsOnlyThatDomain(t *testing.T) {
	manager, system, cfg := newManager(t)
	ctx := context.Background()

	for _, domain := range []string{"example.com", "example.com.au"} {
		if _, err := manager.ApplyDomain(ctx, budi, domain, "rayxo"); err != nil {
			t.Fatal(err)
		}
	}

	if err := manager.RemoveDomain(ctx, &budi, "example.com"); err != nil {
		t.Fatal(err)
	}

	if _, err := os.Stat(filepath.Join(cfg.DKIMDir, "example.com.rayxo.key")); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("the removed domain's key is still there")
	}

	if _, err := os.Stat(filepath.Join(cfg.DKIMDir, "example.com.au.rayxo.key")); err != nil {
		t.Fatalf("another domain's key went too: %v", err)
	}

	content, _ := os.ReadFile(cfg.SelectorMap)
	if strings.Contains(string(content), "example.com rayxo") || !strings.Contains(string(content), "example.com.au rayxo") {
		t.Fatalf("selector map = %q", content)
	}

	// An account that is gone took its mail with it: only the key goes.
	if err := manager.RemoveDomain(ctx, nil, "example.com.au"); err != nil {
		t.Fatal(err)
	}

	if !slices.Equal(system.removed, []string{"/home/budi: imap/example.com"}) {
		t.Fatalf("removed = %v", system.removed)
	}
}

func TestRemoveMailboxDeletesItsMail(t *testing.T) {
	manager, system, _ := newManager(t)

	if err := manager.RemoveMailbox(context.Background(), budi, "example.com", "info.sales"); err != nil {
		t.Fatal(err)
	}

	if !slices.Equal(system.removed, []string{"/home/budi: imap/example.com/info.sales"}) {
		t.Fatalf("removed = %v", system.removed)
	}
}

func TestApplySieveWritesTheScriptInTheMailboxFolder(t *testing.T) {
	manager, system, _ := newManager(t)
	script := "require [\"fileinto\"];\nif header :contains \"subject\" \"invoice\" { fileinto \"Invoices\"; }\n"

	if err := manager.ApplySieve(context.Background(), budi, "example.com", "info", script); err != nil {
		t.Fatal(err)
	}

	if len(system.paths) != 1 || !slices.Equal(system.paths[0].Dirs, []string{"imap", "example.com", "info"}) ||
		system.paths[0].UID != 1001 || system.paths[0].Mode != 0o700 {
		t.Fatalf("folder = %+v", system.paths)
	}

	if system.written["/home/budi: imap/example.com/info/rayxo.sieve"] != script {
		t.Fatalf("written = %v", system.written)
	}
}

func TestAnEmptySieveScriptRemovesItAndItsCompiledForm(t *testing.T) {
	manager, system, _ := newManager(t)

	if err := manager.ApplySieve(context.Background(), budi, "example.com", "info", ""); err != nil {
		t.Fatal(err)
	}

	if !slices.Equal(system.removed, []string{"/home/budi: imap/example.com/info/rayxo.sieve,imap/example.com/info/rayxo.svbin"}) {
		t.Fatalf("removed = %v", system.removed)
	}

	if len(system.written) != 0 || len(system.paths) != 0 {
		t.Fatalf("an empty script wrote something: %+v", system)
	}
}

func TestSieveScriptsAreChecked(t *testing.T) {
	manager, system, _ := newManager(t)
	ctx := context.Background()

	for _, script := range []string{strings.Repeat("a", MaxSieveBytes+1), "keep;\x00", "keep; \xff"} {
		if err := manager.ApplySieve(ctx, budi, "example.com", "info", script); err == nil {
			t.Errorf("script %q... was accepted", script[:min(len(script), 10)])
		}
	}

	if err := manager.ApplySieve(ctx, budi, "example.com", "../x", "keep;"); err == nil {
		t.Error("a bad local part was accepted")
	}

	if len(system.written)+len(system.paths)+len(system.removed) != 0 {
		t.Fatalf("a refused script reached the system: %+v", system)
	}
}

func TestInvalidNamesNeverReachTheSystem(t *testing.T) {
	manager, system, _ := newManager(t)
	ctx := context.Background()

	for _, selector := range []string{"", "Rayxo", "a.b", "-x", strings.Repeat("a", 64)} {
		if _, err := manager.ApplyDomain(ctx, budi, "example.com", selector); err == nil {
			t.Errorf("selector %q was accepted", selector)
		}
	}

	for _, localPart := range []string{"", "..", "a..b", ".a", "a.", "a/b", "../x", "A", strings.Repeat("a", 65)} {
		if err := manager.RemoveMailbox(ctx, budi, "example.com", localPart); err == nil {
			t.Errorf("local part %q was accepted", localPart)
		}
	}

	if _, err := manager.ApplyDomain(ctx, budi, "../etc", "rayxo"); err == nil {
		t.Error("a bad domain was accepted")
	}

	if len(system.paths)+len(system.removed) != 0 {
		t.Fatalf("invalid names reached the system: %+v", system)
	}
}
