package actions

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/binary"
	"encoding/json"
	"fmt"
	"os"
	"slices"
	"strings"
	"testing"

	"rayxo/agent/internal/files"
	"rayxo/agent/internal/jail"
	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/sysuser"
)

// keyHelper is a files helper holding the files of one home in memory.
type keyHelper struct {
	files   map[string]string
	ops     []string
	account files.Account
}

func (h *keyHelper) Run(_ context.Context, account files.Account, op string, params any, _ *os.File, result any) error {
	h.account = account

	switch p := params.(type) {
	case files.PathParams:
		h.ops = append(h.ops, op+" "+p.Path)

		content, exists := h.files[p.Path]

		switch {
		case op == files.OpRead && !exists:
			return &files.Error{Reason: files.ReasonNotFound, Message: p.Path + " does not exist"}
		case op == files.OpRead:
			*result.(*files.Content) = files.Content{Content: content, Version: "v1"}
		case op == files.OpMkdir && exists:
			return &files.Error{Reason: files.ReasonExists, Message: p.Path + " exists already"}
		case op == files.OpMkdir:
			h.files[p.Path] = ""
		}
	case files.WriteParams:
		h.ops = append(h.ops, fmt.Sprintf("%s %s version=%s", op, p.Path, p.Version))
		h.files[p.Path] = p.Content
	case files.ChmodParams:
		h.ops = append(h.ops, fmt.Sprintf("%s %s %o", op, strings.Join(p.Paths, " "), p.Mode))
		*result.(*files.Outcome) = files.Outcome{}
	default:
		return fmt.Errorf("unexpected %s with %T", op, params)
	}

	return nil
}

func callSSH(t *testing.T, system *fakeSystem, helper *keyHelper, method string, params any) (json.RawMessage, error) {
	t.Helper()

	raw, err := json.Marshal(params)
	if err != nil {
		t.Fatal(err)
	}

	registered, ok := sshMethods(system, system, helper)[method]
	if !ok {
		t.Fatalf("unknown method %s", method)
	}

	if _, err := registered.LockKey(raw); err != nil {
		return nil, err
	}

	result, err := registered.Handler(context.Background(), raw)
	if err != nil {
		return nil, err
	}

	encoded, _ := json.Marshal(result)

	return encoded, nil
}

func newKey(t *testing.T, comment string) string {
	t.Helper()

	public, _, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}

	var blob []byte
	for _, part := range [][]byte{[]byte("ssh-ed25519"), public} {
		blob = binary.BigEndian.AppendUint32(blob, uint32(len(part)))
		blob = append(blob, part...)
	}

	return strings.TrimSpace("ssh-ed25519 " + base64.StdEncoding.EncodeToString(blob) + " " + comment)
}

func TestSSHMethodsAreRegisteredAndLockTheAccount(t *testing.T) {
	registry := rpc.NewRegistry()
	Register(registry, Config{})

	for name, raw := range map[string]string{
		"account.set_ssh":  `{"username":"budi","enabled":true}`,
		"account.ssh_keys": `{"username":"budi","keys":[]}`,
	} {
		if !slices.Contains(registry.Names(), name) {
			t.Errorf("%s is not registered", name)
		}

		key, err := sshMethods(newFakeSystem(), newFakeSystem(), &keyHelper{})[name].LockKey(json.RawMessage(raw))
		if err != nil || key != "account:budi" {
			t.Errorf("%s lock key = %q, %v; want account:budi", name, key, err)
		}
	}
}

func TestEnablingSSHBuildsTheJailBeforeTheAccountGetsAShell(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 1001)

	if _, err := callSSH(t, system, &keyHelper{}, "account.set_ssh", map[string]any{"username": "budi", "enabled": true}); err != nil {
		t.Fatal(err)
	}

	assertCalls(t, system, "lookup budi", "jail budi", "group rayxo-ssh budi", "shell budi /bin/bash")

	if want := (jail.Account{Name: "budi", UID: 1001, GID: 1001, Home: "/home/budi"}); system.jails["budi"] != want {
		t.Errorf("jail of %+v, want %+v", system.jails["budi"], want)
	}
}

func TestDisablingSSHTakesTheShellAwayThenRemovesTheJail(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 1001)
	system.groups[sshGroup] = &sysuser.Group{Name: sshGroup, GID: 991, Members: []string{"budi", "sari"}}
	system.jails["budi"] = jail.Account{Name: "budi"}

	if _, err := callSSH(t, system, &keyHelper{}, "account.set_ssh", map[string]any{"username": "budi", "enabled": false}); err != nil {
		t.Fatal(err)
	}

	assertCalls(t, system, "lookup budi", "shell budi /usr/sbin/nologin", "ungroup rayxo-ssh budi", "unjail budi")

	if members := system.groups[sshGroup].Members; !slices.Equal(members, []string{"sari"}) {
		t.Errorf("rayxo-ssh members = %v", members)
	}
}

func TestSSHIsOnlyForHostingAccounts(t *testing.T) {
	system := newFakeSystem()
	system.users["deploy"] = sysuser.User{Name: "deploy", UID: 1000, GID: 1000, Home: "/home/deploy", Shell: "/bin/bash"}

	_, err := callSSH(t, system, &keyHelper{}, "account.set_ssh", map[string]any{"username": "deploy", "enabled": true})

	assertCode(t, err, rpc.CodeActionFailed)
	assertCalls(t, system, "lookup deploy")
}

func TestSuspendingAndDeletingEndTheSSHSessions(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 1001)
	system.jails["budi"] = jail.Account{Name: "budi"}

	if _, err := callAccount(t, system, "account.suspend", map[string]string{"username": "budi"}); err != nil {
		t.Fatal(err)
	}

	assertCalls(t, system, "lookup budi", "lock budi", "shell budi /usr/sbin/nologin", "unjail budi", "web suspend budi", "db lock budi")

	system.calls = nil
	system.jails["budi"] = jail.Account{Name: "budi"}

	if _, err := callAccount(t, system, "account.delete", map[string]string{"username": "budi"}); err != nil {
		t.Fatal(err)
	}

	if unjail, kill := slices.Index(system.calls, "unjail budi"), slices.Index(system.calls, "kill 1001"); unjail < 0 || unjail > kill {
		t.Errorf("the jail was not removed before the user: %v", system.calls)
	}
}

func TestKeysAreWrittenAsTheAccountBetweenTheRAYXOLines(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 1001)

	first, second := newKey(t, "budi@laptop"), newKey(t, "")
	own := newKey(t, "added by hand")
	helper := &keyHelper{files: map[string]string{
		".ssh":             "",
		authorizedKeysPath: own + "\n\n" + keysBegin + "\nssh-ed25519 AAAAold\n" + keysEnd + "\n",
	}}

	result, err := callSSH(t, system, helper, "account.ssh_keys", map[string]any{"username": "budi", "keys": []string{first, "  " + second + " ", first}})
	if err != nil {
		t.Fatal(err)
	}

	if string(result) != `{"keys":2}` {
		t.Errorf("result = %s", result)
	}

	if want := own + "\n\n" + keysBegin + "\n" + first + "\n" + second + "\n" + keysEnd + "\n"; helper.files[authorizedKeysPath] != want {
		t.Errorf("authorized_keys =\n%s\nwant\n%s", helper.files[authorizedKeysPath], want)
	}

	wantOps := []string{"read " + authorizedKeysPath, "mkdir .ssh", "chmod .ssh 700", "write " + authorizedKeysPath + " version=v1", "chmod " + authorizedKeysPath + " 600"}
	if !slices.Equal(helper.ops, wantOps) {
		t.Errorf("helper ops = %q, want %q", helper.ops, wantOps)
	}

	if helper.account != (files.Account{UID: 1001, GID: 1001, Home: "/home/budi"}) {
		t.Errorf("written as %+v", helper.account)
	}
}

func TestTheFirstKeysCreateTheFile(t *testing.T) {
	system := newFakeSystem()
	system.addAccount("budi", 1001)

	key := newKey(t, "")
	helper := &keyHelper{files: map[string]string{}}

	if _, err := callSSH(t, system, helper, "account.ssh_keys", map[string]any{"username": "budi", "keys": []string{key}}); err != nil {
		t.Fatal(err)
	}

	if want := keysBegin + "\n" + key + "\n" + keysEnd + "\n"; helper.files[authorizedKeysPath] != want {
		t.Errorf("authorized_keys = %q", helper.files[authorizedKeysPath])
	}

	if !slices.Contains(helper.ops, "write "+authorizedKeysPath+" version=") {
		t.Errorf("helper ops = %q", helper.ops)
	}
}

func TestKeysThatAreNotPlainPublicKeysAreRefused(t *testing.T) {
	key := newKey(t, "")

	for name, keys := range map[string][]string{
		"a forced command": {`command="/bin/sh" ` + key},
		"two lines in one": {key + "\n" + key},
		"a private key":    {"-----BEGIN OPENSSH PRIVATE KEY-----"},
		"too many keys":    slices.Repeat([]string{key}, maxSSHKeys+1),
	} {
		system := newFakeSystem()
		system.addAccount("budi", 1001)
		helper := &keyHelper{files: map[string]string{}}

		_, err := callSSH(t, system, helper, "account.ssh_keys", map[string]any{"username": "budi", "keys": keys})

		assertCode(t, err, rpc.CodeInvalidParams)

		if len(helper.ops) != 0 {
			t.Errorf("%s reached the home: %v", name, helper.ops)
		}
	}
}

func TestRemovingEveryKeyKeepsTheAccountsOwnLines(t *testing.T) {
	existing := "ssh-ed25519 AAAAown me\n" + keysBegin + "\nssh-ed25519 AAAAold\n" + keysEnd + "\n"

	if got := withKeys(existing, nil); got != "ssh-ed25519 AAAAown me\n" {
		t.Errorf("withKeys = %q", got)
	}

	if got := withKeys("ssh-ed25519 AAAAown me", []string{"ssh-ed25519 AAAAnew"}); got != "ssh-ed25519 AAAAown me\n\n"+keysBegin+"\nssh-ed25519 AAAAnew\n"+keysEnd+"\n" {
		t.Errorf("withKeys after a line without a newline = %q", got)
	}
}
