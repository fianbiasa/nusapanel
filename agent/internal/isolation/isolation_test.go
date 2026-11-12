//go:build integration && linux

package isolation

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
	"testing"
	"time"

	"rayxo/agent/internal/client"
)

var socketPath = flag.String("socket", "/run/rayxo/agent.sock", "agent socket")

// The web server's plain HTTP port; the websites answer there by name.
const webAddress = "127.0.0.1:80"

// account is a hosting account made for the test, with one website.
type account struct {
	Username string `json:"username"`
	UID      int    `json:"uid"`
	GID      int    `json:"gid"`
	Home     string `json:"home"`
	domain   string
}

func (a account) docroot() string {
	return filepath.Join(a.Home, "domains", a.domain, "public_html")
}

// server is the RAYXO server under test, with attacker a and victim b.
type server struct {
	secret string
	php    string
	a, b   account
}

func TestAnAccountCannotReadAnotherAccountsFiles(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("runs as root on a RAYXO server")
	}

	s := setUp(t)

	if !t.Run("controls", s.controls) {
		t.Fatal("the checks below would prove nothing while the controls fail")
	}

	t.Run("as the Linux user (SFTP, cron, file manager)", s.asLinuxUser)
	t.Run("from the website's PHP", s.fromWebsitePHP)
	t.Run("through symlinks served by Nginx and Apache", s.throughSymlinks)
	t.Run("through .htaccess", s.throughHtaccess)
	t.Run("through the other account's PHP-FPM socket", s.throughPHPSocket)
	t.Run("through the file manager", s.throughFileManager)
	t.Run("through a symlinked document root", s.throughDocumentRootSymlink)
	t.Run("a home opened to others would be caught", s.openedHomeIsCaught)
}

func setUp(t *testing.T) *server {
	version := newestPHP(t)
	suffix := randomHex(t, 3)

	s := &server{
		secret: "rayxo-isolation-" + randomHex(t, 16),
		php:    "/usr/bin/php" + version,
	}
	s.a = createAccount(t, "isoa"+suffix, version)
	s.b = createAccount(t, "isob"+suffix, version)

	// The victim's files, as an upload by FTP or SFTP leaves them (0644).
	writeAs(t, s.b, filepath.Join(s.b.docroot(), "secret.txt"), s.secret)
	writeAs(t, s.b, filepath.Join(s.b.docroot(), "secret.html"), s.secret)
	writeAs(t, s.b, filepath.Join(s.b.docroot(), "wp-config.php"), "<?php // "+s.secret+"\n")
	writeAs(t, s.b, filepath.Join(s.b.Home, "notes.txt"), s.secret)

	// The attacker's own files, for the controls.
	writeAs(t, s.a, filepath.Join(s.a.docroot(), "mine.txt"), "mine")
	writeAs(t, s.a, filepath.Join(s.a.docroot(), "mine.html"), "mine")
	writeAs(t, s.a, filepath.Join(s.a.docroot(), "ok.php"), "<?php echo 'php-ok';\n")
	symlinkAs(t, s.a, filepath.Join(s.a.docroot(), "mine.txt"), filepath.Join(s.a.docroot(), "own-link.txt"))
	symlinkAs(t, s.a, filepath.Join(s.a.docroot(), "mine.html"), filepath.Join(s.a.docroot(), "own-link.html"))

	return s
}

// controls show that every channel the checks use works, and that the
// victim's files are readable where they should be, so a refusal below is
// the isolation at work and not a broken test.
func (s *server) controls(t *testing.T) {
	for _, path := range []string{"/secret.txt", "/secret.html"} {
		if _, body := get(t, s.b.domain, path); !strings.Contains(body, s.secret) {
			t.Errorf("%s's own website does not serve %s: %q", s.b.Username, path, body)
		}
	}

	if out := runAs(t, s.b, "cat", filepath.Join(s.b.Home, "notes.txt")); !strings.Contains(out, s.secret) {
		t.Errorf("%s cannot read its own notes.txt: %q", s.b.Username, out)
	}

	if _, body := get(t, s.a.domain, "/ok.php"); body != "php-ok" {
		t.Errorf("%s's PHP does not run: %q", s.a.Username, body)
	}

	for _, path := range []string{"/own-link.txt", "/own-link.html"} {
		if _, body := get(t, s.a.domain, path); body != "mine" {
			t.Errorf("a symlink to %s's own file is not served at %s: %q", s.a.Username, path, body)
		}
	}
}

// asLinuxUser covers everything that runs as the account's Linux user:
// SFTP sessions, cron jobs (PHP CLI there has no open_basedir) and the
// file manager's helper.
func (s *server) asLinuxUser(t *testing.T) {
	s.refute(t, "cat", runAs(t, s.a, "cat", filepath.Join(s.b.docroot(), "secret.txt")))
	s.refute(t, "cat", runAs(t, s.a, "cat", filepath.Join(s.b.Home, "notes.txt")))
	s.refuteListing(t, "ls", runAs(t, s.a, "ls", "-la", s.b.Home))
	s.refuteListing(t, "find", runAs(t, s.a, "find", s.b.Home))

	readScript := fmt.Sprintf(`echo @file_get_contents(%q), @file_get_contents(%q);`,
		filepath.Join(s.b.docroot(), "wp-config.php"), filepath.Join(s.b.Home, "notes.txt"))
	s.refute(t, "PHP CLI", runAs(t, s.a, s.php, "-r", readScript))
}

func (s *server) fromWebsitePHP(t *testing.T) {
	relative := filepath.Join("../../../..", s.b.Username, "notes.txt")
	script := fmt.Sprintf(`<?php
header('Content-Type: text/plain');
foreach ([%q, %q, %q, %q] as $number => $path) {
    $content = @file_get_contents($path);
    echo 'read ', $number, ': ', $content === false ? 'refused' : $content, "\n";
}
$list = @scandir(%q);
echo 'scandir: ', $list === false ? 'refused' : implode(' ', $list), "\n";
$link = sys_get_temp_dir() . '/stolen.txt';
@symlink(%q, $link);
echo 'symlink: ', @file_get_contents($link) ?: 'refused', "\n";
`, filepath.Join(s.b.docroot(), "secret.txt"), filepath.Join(s.b.docroot(), "wp-config.php"),
		filepath.Join(s.b.Home, "notes.txt"), relative, s.b.Home, filepath.Join(s.b.Home, "notes.txt"))
	writeAs(t, s.a, filepath.Join(s.a.docroot(), "read.php"), script)

	_, body := get(t, s.a.domain, "/read.php")
	if !strings.Contains(body, "scandir: ") {
		t.Fatalf("read.php did not run: %q", body)
	}

	s.refute(t, "read.php", body)
	s.refuteListing(t, "read.php", body)
}

// throughSymlinks covers the web server, which may enter every home: a
// link the attacker made to the victim's file or folder must not be served,
// by Nginx (static files) or by Apache (everything else).
func (s *server) throughSymlinks(t *testing.T) {
	symlinkAs(t, s.a, filepath.Join(s.b.docroot(), "secret.txt"), filepath.Join(s.a.docroot(), "link.txt"))
	symlinkAs(t, s.a, filepath.Join(s.b.docroot(), "secret.html"), filepath.Join(s.a.docroot(), "link.html"))
	symlinkAs(t, s.a, filepath.Join(s.b.docroot(), "wp-config.php"), filepath.Join(s.a.docroot(), "link.php"))
	symlinkAs(t, s.a, s.b.docroot(), filepath.Join(s.a.docroot(), "folder"))

	for _, path := range []string{
		"/link.txt", "/link.html", "/link.php",
		"/folder/secret.txt", "/folder/secret.html", "/folder/wp-config.php", "/folder/",
	} {
		status, body := get(t, s.a.domain, path)
		s.refute(t, fmt.Sprintf("GET %s (%d)", path, status), body)
		s.refuteListing(t, fmt.Sprintf("GET %s (%d)", path, status), body)
	}
}

// throughDocumentRootSymlink replaces the attacker's whole document root,
// which the attacker owns and may rename, with a symlink to the victim's
// document root and then to the victim's home: the web server must check
// the document root itself too, not only what is below it.
func (s *server) throughDocumentRootSymlink(t *testing.T) {
	docroot := s.a.docroot()
	moved := docroot + ".moved"

	if err := os.Rename(docroot, moved); err != nil {
		t.Fatal(err)
	}

	t.Cleanup(func() {
		_ = os.Remove(docroot)
		_ = os.Rename(moved, docroot)
	})

	targets := map[string][]string{
		s.b.docroot(): {"/secret.txt", "/secret.html", "/wp-config.php"},
		s.b.Home:      {"/notes.txt"},
	}

	for target, paths := range targets {
		_ = os.Remove(docroot)
		symlinkAs(t, s.a, target, docroot)

		for _, path := range paths {
			status, body := get(t, s.a.domain, path)
			s.refute(t, fmt.Sprintf("GET %s with the document root linked to %s (%d)", path, target, status), body)
		}
	}
}

// throughHtaccess tries to switch the symlink protection off and to have
// Apache list the victim's folder, from the attacker's .htaccess.
func (s *server) throughHtaccess(t *testing.T) {
	follow := mkdirAs(t, s.a, filepath.Join(s.a.docroot(), "follow"))
	writeAs(t, s.a, filepath.Join(follow, ".htaccess"), "Options +FollowSymLinks +Indexes\n")
	symlinkAs(t, s.a, filepath.Join(s.b.docroot(), "secret.html"), filepath.Join(follow, "link.html"))

	indexes := mkdirAs(t, s.a, filepath.Join(s.a.docroot(), "indexes"))
	writeAs(t, s.a, filepath.Join(indexes, ".htaccess"), "Options +Indexes\n")
	symlinkAs(t, s.a, s.b.docroot(), filepath.Join(indexes, "folder"))

	for _, path := range []string{"/follow/link.html", "/indexes/folder/", "/indexes/folder/secret.html"} {
		status, body := get(t, s.a.domain, path)
		s.refute(t, fmt.Sprintf("GET %s (%d)", path, status), body)
		s.refuteListing(t, fmt.Sprintf("GET %s (%d)", path, status), body)
	}
}

// throughPHPSocket tries to get the attacker's code run by the victim's
// PHP-FPM pool, which runs as the victim: through Apache, by pointing the
// attacker's PHP files at that pool in .htaccess, and directly, by
// connecting to the pool's socket.
func (s *server) throughPHPSocket(t *testing.T) {
	handler := victimPHPHandler(t, s.b)

	steal := mkdirAs(t, s.a, filepath.Join(s.a.docroot(), "steal"))
	writeAs(t, s.a, filepath.Join(steal, ".htaccess"), fmt.Sprintf("<FilesMatch \"\\.php$\">\n    SetHandler %q\n</FilesMatch>\n", handler))
	writeAs(t, s.a, filepath.Join(steal, "steal.php"), fmt.Sprintf("<?php echo 'ran: ', @file_get_contents(%q);\n", filepath.Join(s.b.docroot(), "secret.txt")))

	status, body := get(t, s.a.domain, "/steal/steal.php")
	s.refute(t, fmt.Sprintf("GET /steal/steal.php (%d)", status), body)

	socket := strings.TrimPrefix(strings.SplitN(handler, "|", 2)[0], "proxy:unix:")
	connect := fmt.Sprintf(`echo @stream_socket_client(%q, $code, $message, 2) ? 'connected' : 'refused';`, "unix://"+socket)

	if out := runAs(t, s.a, s.php, "-r", connect); out != "refused" {
		t.Errorf("%s's Linux user can connect to %s's PHP-FPM socket %s: %q", s.a.Username, s.b.Username, socket, out)
	}

	writeAs(t, s.a, filepath.Join(s.a.docroot(), "connect.php"), "<?php "+connect+"\n")

	if _, body := get(t, s.a.domain, "/connect.php"); body != "refused" {
		t.Errorf("%s's website can connect to %s's PHP-FPM socket %s: %q", s.a.Username, s.b.Username, socket, body)
	}
}

// throughFileManager asks the agent's file manager, as the attacker, for
// the victim's files: by the links above and by a path that climbs out.
func (s *server) throughFileManager(t *testing.T) {
	inDocroot := filepath.Join("domains", s.a.domain, "public_html")

	for _, call := range []struct{ method, path string }{
		{"fs.read", filepath.Join(inDocroot, "link.txt")},
		{"fs.read", filepath.Join(inDocroot, "folder", "secret.txt")},
		{"fs.list", filepath.Join(inDocroot, "folder")},
		{"fs.read", filepath.Join("..", s.b.Username, "notes.txt")},
	} {
		result, err := callAgent(call.method, map[string]any{"username": s.a.Username, "path": call.path})
		how := fmt.Sprintf("%s %s (error: %v)", call.method, call.path, err)
		s.refute(t, how, string(result))
		s.refuteListing(t, how, string(result))
	}
}

// openedHomeIsCaught opens the victim's home to others for a moment and
// expects the attacker to read it, which shows that the home's mode is
// what stops the reads above and that the check would see a leak.
func (s *server) openedHomeIsCaught(t *testing.T) {
	info, err := os.Stat(s.b.Home)
	if err != nil {
		t.Fatal(err)
	}

	mode := info.Mode().Perm()
	if err := os.Chmod(s.b.Home, mode|0o001); err != nil {
		t.Fatal(err)
	}

	defer func() {
		if err := os.Chmod(s.b.Home, mode); err != nil {
			t.Errorf("restore the mode of %s to %o: %v", s.b.Home, mode, err)
		}
	}()

	if out := runAs(t, s.a, "cat", filepath.Join(s.b.Home, "notes.txt")); !strings.Contains(out, s.secret) {
		t.Errorf("with %s open to others, %s still cannot read notes.txt (%q): the checks would not notice a leak", s.b.Home, s.a.Username, out)
	}
}

// refute fails when output holds the victim's secret.
func (s *server) refute(t *testing.T, how, output string) {
	t.Helper()

	if strings.Contains(output, s.secret) {
		t.Errorf("%s: %s read %s's file:\n%s", how, s.a.Username, s.b.Username, output)

		return
	}

	t.Logf("%s: no secret in %q", how, excerpt(output))
}

// excerpt is the first line of output, shortened, for the log.
func excerpt(output string) string {
	line, _, _ := strings.Cut(output, "\n")
	if len(line) > 90 {
		return line[:90] + "…"
	}

	return line
}

// refuteListing fails when output lists the victim's files.
func (s *server) refuteListing(t *testing.T, how, output string) {
	t.Helper()

	if strings.Contains(output, "notes.txt") || strings.Contains(output, "wp-config.php") {
		t.Errorf("%s: %s listed %s's files:\n%s", how, s.a.Username, s.b.Username, output)
	}
}

func createAccount(t *testing.T, username, phpVersion string) account {
	t.Helper()

	result, err := callAgent("account.create", map[string]any{"username": username})
	if err != nil {
		t.Fatalf("account.create %s: %v", username, err)
	}

	t.Cleanup(func() {
		if _, err := callAgent("account.delete", map[string]any{"username": username}); err != nil {
			t.Errorf("delete test account %s: %v; remove it with: rayxo call account.delete '{\"username\":%q}'", username, err, username)
		}
	})

	var created account
	if err := json.Unmarshal(result, &created); err != nil {
		t.Fatalf("account.create %s: %v", username, err)
	}

	created.domain = username + ".test"

	if _, err := callAgent("web.apply_domain", map[string]any{
		"username":    username,
		"domain":      created.domain,
		"php_version": phpVersion,
	}); err != nil {
		t.Fatalf("web.apply_domain %s: %v", created.domain, err)
	}

	waitForWebsite(t, created.domain)

	return created
}

// waitForWebsite waits until the web servers answer for domain with its
// default page: they reload in the background after the agent returns.
func waitForWebsite(t *testing.T, domain string) {
	t.Helper()

	deadline := time.Now().Add(30 * time.Second)

	for {
		status, _ := get(t, domain, "/")
		if status == http.StatusOK {
			return
		}

		if time.Now().After(deadline) {
			t.Fatalf("%s still answers %d after 30 seconds", domain, status)
		}

		time.Sleep(250 * time.Millisecond)
	}
}

// newestPHP is the newest PHP version with both PHP-FPM and the CLI.
func newestPHP(t *testing.T) string {
	t.Helper()

	result, err := callAgent("php.versions", map[string]any{})
	if err != nil {
		t.Fatalf("php.versions: %v", err)
	}

	var installed struct {
		Versions []string `json:"versions"`
	}
	if err := json.Unmarshal(result, &installed); err != nil {
		t.Fatalf("php.versions: %v", err)
	}

	for i := len(installed.Versions) - 1; i >= 0; i-- {
		if _, err := os.Stat("/usr/bin/php" + installed.Versions[i]); err == nil {
			return installed.Versions[i]
		}
	}

	t.Fatalf("no PHP version has its CLI installed: %v", installed.Versions)

	return ""
}

var handlerPattern = regexp.MustCompile(`SetHandler "(proxy:unix:[^"|]+\|fcgi://[^"]+)"`)

// victimPHPHandler is the SetHandler value of the victim's website, which
// names its PHP-FPM socket.
func victimPHPHandler(t *testing.T, victim account) string {
	t.Helper()

	vhost, err := os.ReadFile(filepath.Join("/etc/apache2/rayxo", victim.Username, victim.domain+".conf"))
	if err != nil {
		t.Fatal(err)
	}

	match := handlerPattern.FindSubmatch(vhost)
	if match == nil {
		t.Fatalf("no PHP handler in the Apache vhost of %s", victim.domain)
	}

	return string(match[1])
}

func callAgent(method string, params any) (json.RawMessage, error) {
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
	defer cancel()

	return client.Call(ctx, *socketPath, method, params)
}

// runAs runs a command as the account's Linux user and returns what it
// printed, errors included: only what it could read matters.
func runAs(t *testing.T, as account, name string, args ...string) string {
	t.Helper()

	cmd := exec.Command("runuser", append([]string{"-u", as.Username, "--", name}, args...)...)
	cmd.Dir = "/"

	out, err := cmd.CombinedOutput()
	if err != nil {
		var exitErr *exec.ExitError
		if !errors.As(err, &exitErr) {
			t.Fatalf("run %s as %s: %v", name, as.Username, err)
		}
	}

	return strings.TrimSpace(string(out))
}

// get asks the web server for path on domain, without following redirects.
func get(t *testing.T, domain, path string) (int, string) {
	t.Helper()

	httpClient := &http.Client{
		Timeout: 30 * time.Second,
		Transport: &http.Transport{
			DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
				var dialer net.Dialer

				return dialer.DialContext(ctx, "tcp", webAddress)
			},
		},
		CheckRedirect: func(*http.Request, []*http.Request) error {
			return http.ErrUseLastResponse
		},
	}

	response, err := httpClient.Get("http://" + domain + path)
	if err != nil {
		t.Fatalf("GET %s%s: %v", domain, path, err)
	}
	defer response.Body.Close()

	body, err := io.ReadAll(io.LimitReader(response.Body, 1<<20))
	if err != nil {
		t.Fatalf("GET %s%s: %v", domain, path, err)
	}

	return response.StatusCode, strings.TrimSpace(string(body))
}

// writeAs writes a file the way the account's own upload would.
func writeAs(t *testing.T, owner account, path, content string) {
	t.Helper()

	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}

	if err := os.Chown(path, owner.UID, owner.GID); err != nil {
		t.Fatal(err)
	}
}

func mkdirAs(t *testing.T, owner account, path string) string {
	t.Helper()

	if err := os.Mkdir(path, 0o755); err != nil {
		t.Fatal(err)
	}

	if err := os.Chown(path, owner.UID, owner.GID); err != nil {
		t.Fatal(err)
	}

	return path
}

// symlinkAs makes a symlink owned by the account, as if it made it itself.
func symlinkAs(t *testing.T, owner account, target, link string) {
	t.Helper()

	if err := os.Symlink(target, link); err != nil && !os.IsExist(err) {
		t.Fatal(err)
	}

	if err := os.Lchown(link, owner.UID, owner.GID); err != nil {
		t.Fatal(err)
	}
}

func randomHex(t *testing.T, bytes int) string {
	t.Helper()

	buffer := make([]byte, bytes)
	if _, err := rand.Read(buffer); err != nil {
		t.Fatal(err)
	}

	return hex.EncodeToString(buffer)
}
