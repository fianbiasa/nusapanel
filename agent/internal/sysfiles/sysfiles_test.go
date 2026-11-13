package sysfiles

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

type fakeRun struct {
	calls []string
	// fail makes calls starting with it fail with output.
	fail   map[string]string
	failed map[string]int
	// failOnce fails a call only the first time.
	failOnce bool
}

func (f *fakeRun) run(_ context.Context, program string, args ...string) ([]byte, error) {
	call := strings.Join(append([]string{filepath.Base(program)}, args...), " ")
	f.calls = append(f.calls, call)

	for prefix, output := range f.fail {
		if strings.HasPrefix(call, prefix) && (!f.failOnce || f.failed[prefix] == 0) {
			f.failed[prefix]++

			return []byte(output), errors.New("exit status 1")
		}
	}

	return nil, nil
}

func newManager(t *testing.T) (*Manager, *fakeRun, Config) {
	t.Helper()

	base := t.TempDir()
	run := &fakeRun{fail: map[string]string{}, failed: map[string]int{}}
	clock := time.Date(2026, 11, 13, 8, 0, 0, 0, time.UTC)

	cfg := Config{
		NginxHTTP:     filepath.Join(base, "nginx/conf.d/rayxo-custom.conf"),
		NginxWebsite:  filepath.Join(base, "nginx/rayxo-custom/website.conf"),
		ApacheWebsite: filepath.Join(base, "apache2/rayxo-custom/website.conf"),
		PHPDir:        filepath.Join(base, "rayxo/php"),
		MariaDB:       filepath.Join(base, "mysql/90-rayxo-custom.cnf"),
		PoolTemplate:  filepath.Join(base, "rayxo/php-fpm-pool.conf"),
		BackupDir:     filepath.Join(base, "backups"),
		PHPVersions:   func() ([]string, error) { return []string{"8.3", "8.4"}, nil },
		Run:           run.run,
		Now: func() time.Time {
			clock = clock.Add(time.Second)

			return clock
		},
	}

	return New(cfg), run, cfg
}

func read(t *testing.T, path string) string {
	t.Helper()

	content, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}

	return string(content)
}

func TestFilesAreTheFixedListWithAPHPFilePerVersion(t *testing.T) {
	manager, _, cfg := newManager(t)

	files, err := manager.Files()
	if err != nil {
		t.Fatal(err)
	}

	var ids []string
	for _, file := range files {
		ids = append(ids, file.ID)
	}

	if strings.Join(ids, " ") != "nginx-http nginx-website apache-website php-8.3 php-8.4 mariadb php-fpm-pool" {
		t.Errorf("ids = %v", ids)
	}

	if files[4].Path != filepath.Join(cfg.PHPDir, "8.4", "99-custom.ini") {
		t.Errorf("php-8.4 path = %s", files[4].Path)
	}
}

func TestWriteTestsAppliesAndBacksUp(t *testing.T) {
	manager, run, cfg := newManager(t)

	content, err := manager.Read("nginx-http")
	if err != nil || content.Content != "" || len(content.Backups) != 0 {
		t.Fatalf("Read = %+v, %v", content, err)
	}

	first, err := manager.Write(context.Background(), "nginx-http", "gzip on;", content.Hash)
	if err != nil {
		t.Fatal(err)
	}

	if read(t, cfg.NginxHTTP) != "gzip on;\n" {
		t.Errorf("file = %q", read(t, cfg.NginxHTTP))
	}

	if strings.Join(run.calls, ",") != "nginx -t,systemctl reload nginx" {
		t.Errorf("calls = %q", run.calls)
	}

	if _, err := manager.Write(context.Background(), "nginx-http", "gzip off;\n", first); err != nil {
		t.Fatal(err)
	}

	after, _ := manager.Read("nginx-http")
	if after.Content != "gzip off;\n" || len(after.Backups) != 1 || after.Backups[0].Size != int64(len("gzip on;\n")) {
		t.Errorf("after = %+v", after)
	}
}

func TestAChangeMadeSinceReadingIsRefused(t *testing.T) {
	manager, run, _ := newManager(t)
	content, _ := manager.Read("nginx-http")

	if _, err := manager.Write(context.Background(), "nginx-http", "gzip on;\n", content.Hash); err != nil {
		t.Fatal(err)
	}

	run.calls = nil

	if _, err := manager.Write(context.Background(), "nginx-http", "gzip off;\n", content.Hash); !errors.Is(err, ErrChanged) {
		t.Fatalf("err = %v, want ErrChanged", err)
	}

	if len(run.calls) != 0 {
		t.Errorf("calls = %q", run.calls)
	}
}

func TestARejectedChangeIsRolledBack(t *testing.T) {
	manager, run, cfg := newManager(t)
	content, _ := manager.Read("nginx-website")
	hash, _ := manager.Write(context.Background(), "nginx-website", "add_header X-Test 1;\n", content.Hash)

	run.fail["nginx -t"] = "nginx: [emerg] unknown directive \"gzpi\" in website.conf:1"
	run.calls = nil

	_, err := manager.Write(context.Background(), "nginx-website", "gzpi on;\n", hash)

	var invalid *InvalidError
	if !errors.As(err, &invalid) || !strings.Contains(invalid.Reason, `nginx rejected the change: nginx: [emerg] unknown directive "gzpi"`) {
		t.Fatalf("err = %v", err)
	}

	if read(t, cfg.NginxWebsite) != "add_header X-Test 1;\n" {
		t.Errorf("file = %q", read(t, cfg.NginxWebsite))
	}

	if strings.Join(run.calls, ",") != "nginx -t" {
		t.Errorf("calls = %q", run.calls)
	}
}

func TestARejectedNewFileIsRemoved(t *testing.T) {
	manager, run, cfg := newManager(t)
	run.fail["apache2ctl configtest"] = "Syntax error"
	content, _ := manager.Read("apache-website")

	if _, err := manager.Write(context.Background(), "apache-website", "Bogus\n", content.Hash); err == nil {
		t.Fatal("no error")
	}

	if _, err := os.Stat(cfg.ApacheWebsite); !os.IsNotExist(err) {
		t.Error("the rejected new file stayed")
	}
}

func TestMariaDBThatDoesNotStartGetsTheOldFileBack(t *testing.T) {
	manager, run, cfg := newManager(t)
	content, _ := manager.Read("mariadb")
	hash, err := manager.Write(context.Background(), "mariadb", "[mariadbd]\nmax_connections = 200\n", content.Hash)
	if err != nil {
		t.Fatal(err)
	}

	run.fail["systemctl restart mariadb"] = "Job for mariadb.service failed"
	run.failOnce = true
	run.calls = nil

	_, err = manager.Write(context.Background(), "mariadb", "[mariadbd]\ninnodb_buffer_pool_size = 900T\n", hash)

	var invalid *InvalidError
	if !errors.As(err, &invalid) || !strings.HasSuffix(invalid.Reason, "the previous file was put back") {
		t.Fatalf("err = %v", err)
	}

	if read(t, cfg.MariaDB) != "[mariadbd]\nmax_connections = 200\n" {
		t.Errorf("file = %q", read(t, cfg.MariaDB))
	}

	if strings.Join(run.calls, ",") != "systemctl restart mariadb,systemctl restart mariadb" {
		t.Errorf("calls = %q", run.calls)
	}
}

func TestPHPAdditionsRestartThePoolsOfTheirVersion(t *testing.T) {
	manager, run, cfg := newManager(t)
	content, _ := manager.Read("php-8.4")

	if _, err := manager.Write(context.Background(), "php-8.4", "memory_limit = 512M\n[Date]\ndate.timezone = Asia/Jakarta\n", content.Hash); err != nil {
		t.Fatal(err)
	}

	if read(t, filepath.Join(cfg.PHPDir, "8.4", "99-custom.ini")) == "" {
		t.Error("not written")
	}

	if strings.Join(run.calls, ",") != "systemctl try-restart rayxo-php84@*.service" {
		t.Errorf("calls = %q", run.calls)
	}
}

func TestContentIsCheckedBeforeItIsWritten(t *testing.T) {
	cases := map[string]string{
		"php-8.4":      "memory_limit 512M\n",
		"mariadb":      "max_connections = 200\n",
		"php-fpm-pool": "user = root\n",
	}

	for id, content := range cases {
		manager, run, _ := newManager(t)
		current, _ := manager.Read(id)

		_, err := manager.Write(context.Background(), id, content, current.Hash)

		var invalid *InvalidError
		if !errors.As(err, &invalid) {
			t.Errorf("%s: err = %v", id, err)
		}

		if len(run.calls) != 0 {
			t.Errorf("%s: calls = %q", id, run.calls)
		}
	}

	manager, _, _ := newManager(t)
	current, _ := manager.Read("mariadb")

	for _, content := range []string{"[mariadbd]\n!includedir /etc/shadow\n", "bad\x00text", strings.Repeat("x", MaxSize+1)} {
		if _, err := manager.Write(context.Background(), "mariadb", content, current.Hash); err == nil {
			t.Errorf("Write(%.20q) = nil", content)
		}
	}
}

func TestOnlyTheListedFilesExist(t *testing.T) {
	manager, _, _ := newManager(t)

	for _, id := range []string{"", "../etc/passwd", "/etc/shadow", "php-7.4", "nginx"} {
		if _, err := manager.Read(id); !errors.Is(err, ErrUnknownFile) {
			t.Errorf("Read(%q) = %v", id, err)
		}
	}
}

func TestABackupCanBeRestoredAndOnlyTenAreKept(t *testing.T) {
	manager, _, cfg := newManager(t)
	content, _ := manager.Read("nginx-http")
	hash := content.Hash

	for i := range 13 {
		var err error
		if hash, err = manager.Write(context.Background(), "nginx-http", "# version "+string(rune('a'+i))+"\n", hash); err != nil {
			t.Fatal(err)
		}
	}

	current, _ := manager.Read("nginx-http")
	if len(current.Backups) != 10 {
		t.Fatalf("backups = %d", len(current.Backups))
	}

	newest := current.Backups[0]
	if _, err := manager.Restore(context.Background(), "nginx-http", newest.ID, current.Hash); err != nil {
		t.Fatal(err)
	}

	if read(t, cfg.NginxHTTP) != "# version l\n" {
		t.Errorf("file = %q", read(t, cfg.NginxHTTP))
	}

	for _, backup := range []string{"../../etc/passwd", "123", ""} {
		if _, err := manager.Restore(context.Background(), "nginx-http", backup, hash); !errors.Is(err, ErrNoBackup) {
			t.Errorf("Restore(%q) = %v", backup, err)
		}
	}
}
