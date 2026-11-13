package cgroup

import (
	"os"
	"path/filepath"
	"reflect"
	"syscall"
	"testing"
)

// writeGroup creates a cgroup directory with its CPU time and memory, and
// returns its id.
func writeGroup(t *testing.T, dir, cpu, memory, memoryStat string) uint64 {
	t.Helper()

	if err := os.MkdirAll(dir, 0o755); err != nil {
		t.Fatal(err)
	}

	files := map[string]string{"cpu.stat": "usage_usec " + cpu + "\nuser_usec 1\nsystem_usec 2\n"}

	if memory != "" {
		files["memory.current"] = memory + "\n"
	}

	if memoryStat != "" {
		files["memory.stat"] = memoryStat
	}

	for name, content := range files {
		if err := os.WriteFile(filepath.Join(dir, name), []byte(content), 0o644); err != nil {
			t.Fatal(err)
		}
	}

	info, err := os.Stat(dir)
	if err != nil {
		t.Fatal(err)
	}

	return info.Sys().(*syscall.Stat_t).Ino
}

func TestUsageReadsTheAccountsCgroups(t *testing.T) {
	root := t.TempDir()
	manager := New(Config{Root: root})

	// budi: its slice (with 20 MB of file cache the kernel can take back),
	// its SSH session, and a PHP 8.3 pool still outside its slice; another
	// account's pool and slice and cron are not budi's.
	slice := writeGroup(t, filepath.Join(root, "rayxo.slice", "rayxo-budi.slice"), "1000000", "50000000", "anon 30000000\ninactive_file 20000000\n")
	session := writeGroup(t, filepath.Join(root, "user.slice", "user-1001.slice"), "250000", "10000000", "")
	pool := writeGroup(t, filepath.Join(root, "system.slice", `system-rayxo\x2dphp83.slice`, "rayxo-php83@budi.service"), "500000", "", "")
	writeGroup(t, filepath.Join(root, "system.slice", `system-rayxo\x2dphp83.slice`, "rayxo-php83@sari.service"), "7", "7", "")
	writeGroup(t, filepath.Join(root, "system.slice", "cron.service"), "999999", "999999", "")
	writeGroup(t, filepath.Join(root, "rayxo.slice", "rayxo-sari.slice"), "3", "3", "")

	usages, err := manager.Usage([]Account{{Name: "budi", UID: 1001}, {Name: "tono", UID: 1003}})
	if err != nil {
		t.Fatal(err)
	}

	want := []Usage{
		{Username: "budi", MemoryBytes: 40000000, CPU: []GroupCPU{{ID: slice, Usec: 1000000}, {ID: session, Usec: 250000}, {ID: pool, Usec: 500000}}},
		{Username: "tono", CPU: []GroupCPU{}},
	}

	if !reflect.DeepEqual(usages, want) {
		t.Errorf("usages = %+v", usages)
	}
}

func TestUsageWithoutCgroupsIsNothing(t *testing.T) {
	manager := New(Config{Root: filepath.Join(t.TempDir(), "missing")})

	usages, err := manager.Usage([]Account{{Name: "budi", UID: 1001}})
	if err != nil || !reflect.DeepEqual(usages, []Usage{{Username: "budi", CPU: []GroupCPU{}}}) {
		t.Fatalf("usages = %+v, %v", usages, err)
	}
}

func TestUsageRefusesBadNames(t *testing.T) {
	manager := New(Config{Root: t.TempDir()})

	if _, err := manager.Usage([]Account{{Name: "../etc", UID: 0}}); err == nil {
		t.Fatal("no error")
	}
}
