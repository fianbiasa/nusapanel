package actions

import (
	"context"
	"encoding/json"
	"errors"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/sysfiles"
)

func configFileEnv(t *testing.T, fail string) map[string]rpc.Method {
	t.Helper()

	base := t.TempDir()
	cfg := sysfiles.Config{
		NginxHTTP:     filepath.Join(base, "rayxo-custom.conf"),
		NginxWebsite:  filepath.Join(base, "website.conf"),
		ApacheWebsite: filepath.Join(base, "apache.conf"),
		PHPDir:        filepath.Join(base, "php"),
		MariaDB:       filepath.Join(base, "mariadb.cnf"),
		PoolTemplate:  filepath.Join(base, "pool.conf"),
		BackupDir:     filepath.Join(base, "backups"),
		PHPVersions:   func() ([]string, error) { return []string{"8.4"}, nil },
		Run: func(_ context.Context, program string, _ ...string) ([]byte, error) {
			if fail != "" && strings.HasSuffix(program, fail) {
				return []byte(fail + ": bad"), errors.New("exit status 1")
			}

			return nil, nil
		},
	}

	return configFileMethods(sysfiles.New(cfg))
}

func callConfigFile(t *testing.T, methods map[string]rpc.Method, method string, params any) (map[string]any, error) {
	t.Helper()

	raw, _ := json.Marshal(params)

	result, err := methods[method].Handler(context.Background(), raw)
	if err != nil {
		return nil, err
	}

	encoded, _ := json.Marshal(result)

	var decoded map[string]any
	_ = json.Unmarshal(encoded, &decoded)

	return decoded, nil
}

func TestConfigFileMethodsAreRegistered(t *testing.T) {
	registry := rpc.NewRegistry()
	Register(registry, Config{})

	for _, name := range []string{"system.files", "system.file_read", "system.file_write", "system.file_restore"} {
		if !slices.Contains(registry.Names(), name) {
			t.Errorf("method %s is not registered", name)
		}
	}
}

func TestAConfigFileIsReadWrittenAndRestored(t *testing.T) {
	methods := configFileEnv(t, "")

	list, err := callConfigFile(t, methods, "system.files", map[string]any{})
	if err != nil || len(list["files"].([]any)) != 6 {
		t.Fatalf("list = %v, %v", list, err)
	}

	read, err := callConfigFile(t, methods, "system.file_read", map[string]string{"file": "nginx-http"})
	if err != nil || read["content"] != "" {
		t.Fatalf("read = %v, %v", read, err)
	}

	written, err := callConfigFile(t, methods, "system.file_write", map[string]any{"file": "nginx-http", "content": "gzip on;\n", "hash": read["hash"]})
	if err != nil {
		t.Fatal(err)
	}

	again, _ := callConfigFile(t, methods, "system.file_write", map[string]any{"file": "nginx-http", "content": "gzip off;\n", "hash": written["hash"]})
	read, _ = callConfigFile(t, methods, "system.file_read", map[string]string{"file": "nginx-http"})
	backup := read["backups"].([]any)[0].(map[string]any)["id"]

	if _, err := callConfigFile(t, methods, "system.file_restore", map[string]any{"file": "nginx-http", "backup": backup, "hash": again["hash"]}); err != nil {
		t.Fatal(err)
	}

	read, _ = callConfigFile(t, methods, "system.file_read", map[string]string{"file": "nginx-http"})
	if read["content"] != "gzip on;\n" {
		t.Errorf("content = %q", read["content"])
	}
}

func TestConfigFileFailuresAreExplained(t *testing.T) {
	methods := configFileEnv(t, "nginx")
	read, _ := callConfigFile(t, methods, "system.file_read", map[string]string{"file": "nginx-http"})

	_, err := callConfigFile(t, methods, "system.file_write", map[string]any{"file": "nginx-http", "content": "bogus;\n", "hash": read["hash"]})
	if agentErr := assertCode(t, err, rpc.CodeActionFailed); agentErr.Message != "nginx rejected the change: nginx: bad" {
		t.Errorf("message = %q", agentErr.Message)
	}

	_, err = callConfigFile(t, methods, "system.file_write", map[string]any{"file": "nginx-http", "content": "x;\n", "hash": "stale"})
	if agentErr := assertCode(t, err, rpc.CodeActionFailed); agentErr.Message != "the file changed since it was opened; open it again" {
		t.Errorf("message = %q", agentErr.Message)
	}

	_, err = callConfigFile(t, methods, "system.file_read", map[string]string{"file": "/etc/shadow"})
	assertCode(t, err, rpc.CodeInvalidParams)

	_, err = callConfigFile(t, methods, "system.file_write", map[string]any{"file": "nginx-http", "content": "x", "hash": "", "path": "/etc/passwd"})
	assertCode(t, err, rpc.CodeInvalidParams)
}
