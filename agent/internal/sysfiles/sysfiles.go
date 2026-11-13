// Package sysfiles lets the administrator edit a fixed set of the
// server's configuration files: RAYXO's own files for additions to Nginx,
// Apache, PHP and MariaDB, which setup.sh never overwrites. Files are
// named by ID, never by path. Every change is checked, tested by the
// program that reads the file and applied; a change the program rejects
// is rolled back. The previous versions are kept as backups.
package sysfiles

import (
	"bufio"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"sync"
	"time"
	"unicode/utf8"

	"rayxo/agent/internal/fpmpool"
)

const systemctlProgram = "/usr/bin/systemctl"

// MaxSize is the largest file the editor takes.
const MaxSize = 256 << 10

// keepBackups is how many earlier versions of a file are kept.
const keepBackups = 10

var (
	// ErrUnknownFile means the ID names no editable file.
	ErrUnknownFile = errors.New("no such editable file")
	// ErrChanged means the file changed since it was read.
	ErrChanged = errors.New("the file changed since it was read")
	// ErrNoBackup means the backup does not exist.
	ErrNoBackup = errors.New("no such backup")
)

// InvalidError is a change refused before it was written, or rolled back
// because the program that reads the file rejected it.
type InvalidError struct {
	Reason string
}

func (e *InvalidError) Error() string { return e.Reason }

// File is an editable file.
type File struct {
	ID   string `json:"id"`
	Path string `json:"path"`
	// check validates the content before it is written.
	check func(content string) error
	// test runs after it is written; failing, the change is rolled back.
	test []string
	// apply makes the programs read it; failing with rollback set, the
	// change is rolled back and applied again.
	apply    [][]string
	rollback bool
}

// Backup is an earlier version of a file.
type Backup struct {
	ID        string    `json:"id"`
	Size      int64     `json:"size"`
	CreatedAt time.Time `json:"created_at"`
}

// Content is a file as it is now.
type Content struct {
	File    File     `json:"file"`
	Content string   `json:"content"`
	Hash    string   `json:"hash"`
	Backups []Backup `json:"backups"`
}

// Config holds the locations of the editable files and how programs run.
type Config struct {
	NginxHTTP     string
	NginxWebsite  string
	ApacheWebsite string
	// PHPDir holds <version>/99-custom.ini.
	PHPDir       string
	MariaDB      string
	PoolTemplate string
	// BackupDir holds <ID>/<unix nanoseconds>.bak.
	BackupDir   string
	PHPVersions func() ([]string, error)
	Run         func(ctx context.Context, program string, args ...string) ([]byte, error)
	Now         func() time.Time
}

// DefaultConfig returns the locations on a server.
func DefaultConfig() Config {
	return Config{
		NginxHTTP:     "/etc/nginx/conf.d/rayxo-custom.conf",
		NginxWebsite:  "/etc/nginx/rayxo-custom/website.conf",
		ApacheWebsite: "/etc/apache2/rayxo-custom/website.conf",
		PHPDir:        "/etc/rayxo/php",
		MariaDB:       "/etc/mysql/mariadb.conf.d/90-rayxo-custom.cnf",
		PoolTemplate:  "/etc/rayxo/php-fpm-pool.conf",
		BackupDir:     "/var/lib/rayxo/file-backups",
	}
}

// Manager reads and changes the editable files, one change at a time.
type Manager struct {
	cfg Config
	mu  sync.Mutex
}

// New returns a Manager for cfg.
func New(cfg Config) *Manager {
	return &Manager{cfg: cfg}
}

var phpVersionPattern = regexp.MustCompile(`^[0-9]\.[0-9]$`)

// Files returns the editable files: one php.ini addition per installed
// PHP version.
func (m *Manager) Files() ([]File, error) {
	nginxTest := []string{"/usr/sbin/nginx", "-t"}
	nginxReload := [][]string{{systemctlProgram, "reload", "nginx"}}
	apacheTest := []string{"/usr/sbin/apache2ctl", "configtest"}

	files := []File{
		{ID: "nginx-http", Path: m.cfg.NginxHTTP, check: checkText, test: nginxTest, apply: nginxReload},
		{ID: "nginx-website", Path: m.cfg.NginxWebsite, check: checkText, test: nginxTest, apply: nginxReload},
		{ID: "apache-website", Path: m.cfg.ApacheWebsite, check: checkText, test: apacheTest, apply: [][]string{{systemctlProgram, "reload", "apache2"}}},
	}

	versions, err := m.cfg.PHPVersions()
	if err != nil {
		return nil, err
	}

	for _, version := range versions {
		if !phpVersionPattern.MatchString(version) {
			continue
		}

		tag := strings.ReplaceAll(version, ".", "")
		files = append(files, File{
			ID:    "php-" + version,
			Path:  filepath.Join(m.cfg.PHPDir, version, "99-custom.ini"),
			check: checkINI,
			// The pools started before their unit read this folder only
			// get it when they start again.
			apply: [][]string{{systemctlProgram, "try-restart", "rayxo-php" + tag + "@*.service"}},
		})
	}

	return append(files,
		File{ID: "mariadb", Path: m.cfg.MariaDB, check: checkMariaDB, apply: [][]string{{systemctlProgram, "restart", "mariadb"}}, rollback: true},
		File{ID: "php-fpm-pool", Path: m.cfg.PoolTemplate, check: checkPool},
	), nil
}

func (m *Manager) file(id string) (File, error) {
	files, err := m.Files()
	if err != nil {
		return File{}, err
	}

	index := slices.IndexFunc(files, func(file File) bool { return file.ID == id })
	if index < 0 {
		return File{}, ErrUnknownFile
	}

	return files[index], nil
}

// Read returns the file's content (empty while it does not exist), its
// hash for Write, and its backups, newest first.
func (m *Manager) Read(id string) (Content, error) {
	file, err := m.file(id)
	if err != nil {
		return Content{}, err
	}

	content, err := readFile(file.Path)
	if err != nil {
		return Content{}, err
	}

	backups, err := m.backups(file.ID)
	if err != nil {
		return Content{}, err
	}

	return Content{File: file, Content: content, Hash: hash(content), Backups: backups}, nil
}

// Write replaces the file with content, if it still has hash. The
// previous content is backed up first.
func (m *Manager) Write(ctx context.Context, id, content, expected string) (string, error) {
	file, err := m.file(id)
	if err != nil {
		return "", err
	}

	m.mu.Lock()
	defer m.mu.Unlock()

	return m.write(ctx, file, content, expected)
}

// Restore puts a backup back in place of the file, if it still has hash.
func (m *Manager) Restore(ctx context.Context, id, backup, expected string) (string, error) {
	file, err := m.file(id)
	if err != nil {
		return "", err
	}

	if _, err := strconv.ParseInt(backup, 10, 64); err != nil {
		return "", ErrNoBackup
	}

	content, err := os.ReadFile(filepath.Join(m.cfg.BackupDir, file.ID, backup+".bak"))
	if errors.Is(err, fs.ErrNotExist) {
		return "", ErrNoBackup
	}

	if err != nil {
		return "", err
	}

	m.mu.Lock()
	defer m.mu.Unlock()

	return m.write(ctx, file, string(content), expected)
}

func (m *Manager) write(ctx context.Context, file File, content, expected string) (string, error) {
	if len(content) > MaxSize {
		return "", &InvalidError{Reason: fmt.Sprintf("the file may be %d KB at most", MaxSize>>10)}
	}

	if !utf8.ValidString(content) || strings.ContainsRune(content, 0) {
		return "", &InvalidError{Reason: "the file must be text"}
	}

	if content != "" && !strings.HasSuffix(content, "\n") {
		content += "\n"
	}

	current, err := readFile(file.Path)
	if err != nil {
		return "", err
	}

	_, statErr := os.Stat(file.Path)
	existed := statErr == nil

	if hash(current) != expected {
		return "", ErrChanged
	}

	if err := file.check(content); err != nil {
		return "", &InvalidError{Reason: err.Error()}
	}

	if existed {
		if err := m.backup(file.ID, current); err != nil {
			return "", err
		}
	}

	if err := writeFile(file.Path, content); err != nil {
		return "", err
	}

	undo := func() error {
		if existed {
			return writeFile(file.Path, current)
		}

		return removeFile(file.Path)
	}

	if len(file.test) > 0 {
		if output, err := m.cfg.Run(ctx, file.test[0], file.test[1:]...); err != nil {
			return "", errors.Join(&InvalidError{Reason: rejected(file.test[0], output)}, undo())
		}
	}

	for _, command := range file.apply {
		output, err := m.cfg.Run(ctx, command[0], command[1:]...)
		if err == nil {
			continue
		}

		if !file.rollback {
			return "", fmt.Errorf("apply %s: %w", file.ID, err)
		}

		if undoErr := undo(); undoErr != nil {
			return "", errors.Join(err, undoErr)
		}

		if _, againErr := m.cfg.Run(ctx, command[0], command[1:]...); againErr != nil {
			return "", fmt.Errorf("the change was rolled back, but %s still fails: %w", file.ID, againErr)
		}

		return "", &InvalidError{Reason: rejected(command[len(command)-1], output) + "; the previous file was put back"}
	}

	return hash(content), m.prune(file.ID)
}

func (m *Manager) backup(id, content string) error {
	dir := filepath.Join(m.cfg.BackupDir, id)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return err
	}

	return os.WriteFile(filepath.Join(dir, strconv.FormatInt(m.now().UnixNano(), 10)+".bak"), []byte(content), 0o600)
}

func (m *Manager) backups(id string) ([]Backup, error) {
	entries, err := os.ReadDir(filepath.Join(m.cfg.BackupDir, id))
	if errors.Is(err, fs.ErrNotExist) {
		return []Backup{}, nil
	}

	if err != nil {
		return nil, err
	}

	backups := []Backup{}

	for _, entry := range entries {
		name, found := strings.CutSuffix(entry.Name(), ".bak")
		nanos, err := strconv.ParseInt(name, 10, 64)

		if !found || err != nil {
			continue
		}

		info, err := entry.Info()
		if err != nil {
			return nil, err
		}

		backups = append(backups, Backup{ID: name, Size: info.Size(), CreatedAt: time.Unix(0, nanos).UTC()})
	}

	slices.SortFunc(backups, func(a, b Backup) int { return b.CreatedAt.Compare(a.CreatedAt) })

	return backups, nil
}

func (m *Manager) prune(id string) error {
	backups, err := m.backups(id)
	if err != nil {
		return err
	}

	for _, old := range backups[min(len(backups), keepBackups):] {
		if err := removeFile(filepath.Join(m.cfg.BackupDir, id, old.ID+".bak")); err != nil {
			return err
		}
	}

	return nil
}

func (m *Manager) now() time.Time {
	if m.cfg.Now != nil {
		return m.cfg.Now()
	}

	return time.Now()
}

// rejected is the reason a program gave, kept short.
func rejected(program string, output []byte) string {
	text := strings.TrimSpace(string(output))
	if len(text) > 1500 {
		text = text[:1500] + "…"
	}

	if text == "" {
		return filepath.Base(program) + " rejected the change"
	}

	return filepath.Base(program) + " rejected the change: " + text
}

func hash(content string) string {
	sum := sha256.Sum256([]byte(content))

	return hex.EncodeToString(sum[:])
}

func readFile(path string) (string, error) {
	content, err := os.ReadFile(path)
	if errors.Is(err, fs.ErrNotExist) {
		return "", nil
	}

	return string(content), err
}

func writeFile(path, content string) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return err
	}

	temp := path + ".rayxo-tmp"
	if err := os.WriteFile(temp, []byte(content), 0o644); err != nil {
		return err
	}

	if err := os.Rename(temp, path); err != nil {
		_ = os.Remove(temp)

		return err
	}

	return nil
}

func removeFile(path string) error {
	if err := os.Remove(path); err != nil && !errors.Is(err, fs.ErrNotExist) {
		return err
	}

	return nil
}

// checkText accepts any text; the program that reads it tests it.
func checkText(string) error { return nil }

var iniKeyPattern = regexp.MustCompile(`^[A-Za-z0-9_.\-\[\]]+$`)

// checkINI accepts php.ini lines: sections, "key = value" and comments.
func checkINI(content string) error {
	return checkLines(content, ";", func(line string) error {
		if strings.HasPrefix(line, "[") && strings.HasSuffix(line, "]") {
			return nil
		}

		key, _, found := strings.Cut(line, "=")
		if !found || !iniKeyPattern.MatchString(strings.TrimSpace(key)) {
			return errors.New(`expected "key = value"`)
		}

		return nil
	})
}

// checkMariaDB accepts option file lines in a [section]: "key = value",
// a bare option, and comments; no !include, which could pull in anything.
func checkMariaDB(content string) error {
	inSection := false

	return checkLines(content, "#;", func(line string) error {
		if strings.HasPrefix(line, "[") && strings.HasSuffix(line, "]") {
			inSection = true

			return nil
		}

		if strings.HasPrefix(line, "!") {
			return errors.New("!include and !includedir are not allowed")
		}

		if !inSection {
			return errors.New("options must be in a section such as [mariadbd]")
		}

		key, _, _ := strings.Cut(line, "=")
		if !iniKeyPattern.MatchString(strings.TrimSpace(key)) {
			return errors.New(`expected "option = value"`)
		}

		return nil
	})
}

func checkPool(content string) error {
	_, err := fpmpool.Parse(content)

	return err
}

// checkLines runs check on every line that is not empty or a comment.
func checkLines(content, comments string, check func(line string) error) error {
	scanner := bufio.NewScanner(strings.NewReader(content))
	scanner.Buffer(make([]byte, 0, 64<<10), MaxSize)

	for number := 1; scanner.Scan(); number++ {
		line := strings.TrimSpace(scanner.Text())
		if line == "" || strings.ContainsAny(line[:1], comments) {
			continue
		}

		if err := check(line); err != nil {
			return fmt.Errorf("line %d: %w", number, err)
		}
	}

	return scanner.Err()
}
