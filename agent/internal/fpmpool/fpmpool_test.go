package fpmpool

import (
	"os"
	"path/filepath"
	"slices"
	"testing"
)

func TestParseReplacesTheDefaultsAndKeepsTheirOrder(t *testing.T) {
	settings, err := Parse("; the server's pools\npm = dynamic\npm.max_children = 20\n# spares\npm.min_spare_servers = 2\npm.max_spare_servers = 6\npm.start_servers = 3\n")
	if err != nil {
		t.Fatal(err)
	}

	want := []Setting{
		{"pm", "dynamic"},
		{"pm.max_children", "20"},
		{"pm.process_idle_timeout", "30s"},
		{"pm.max_requests", "500"},
		{"request_terminate_timeout", "300s"},
		{"pm.min_spare_servers", "2"},
		{"pm.max_spare_servers", "6"},
		{"pm.start_servers", "3"},
	}

	if !slices.Equal(settings, want) {
		t.Errorf("settings = %v", settings)
	}
}

func TestParseRefusesWhatIsNotProcessManagement(t *testing.T) {
	for _, content := range []string{
		"user = root",
		"listen = /tmp/x.sock",
		"php_admin_value[disable_functions] = ",
		"pm = adaptive",
		"pm.max_children = 0",
		"pm.max_children = 5000",
		"pm.max_children = ten",
		"request_terminate_timeout = 5 minutes",
		"pm.max_requests",
		"pm = static\npm = ondemand",
		"pm = dynamic\npm.max_children = 10",
		"pm = dynamic\npm.max_children = 10\npm.min_spare_servers = 5\npm.max_spare_servers = 2",
		"pm = dynamic\npm.max_children = 10\npm.min_spare_servers = 2\npm.max_spare_servers = 4\npm.start_servers = 8",
	} {
		if _, err := Parse(content); err == nil {
			t.Errorf("Parse(%q) = nil error", content)
		}
	}
}

func TestLoadWithoutATemplateGivesTheDefaults(t *testing.T) {
	settings, err := Load(filepath.Join(t.TempDir(), "missing.conf"))
	if err != nil || !slices.Equal(settings, Defaults) {
		t.Fatalf("Load = %v, %v", settings, err)
	}

	path := filepath.Join(t.TempDir(), "pool.conf")
	if err := os.WriteFile(path, []byte("pm.max_children = 4\n"), 0o644); err != nil {
		t.Fatal(err)
	}

	settings, err = Load(path)
	if err != nil || settings[1].Value != "4" {
		t.Fatalf("Load = %v, %v", settings, err)
	}
}
