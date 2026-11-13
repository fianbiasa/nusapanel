//go:build linux

package sitelogs

import (
	"bytes"
	"context"
	"fmt"
	"io"
	"os"
	"os/exec"
	"os/user"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"time"
)

const (
	goaccessProgram = "/usr/bin/goaccess"
	// maxReportBytes is the largest report the agent passes on.
	maxReportBytes = 64 << 20
)

// DefaultConfig is the configuration of a RAYXO server.
func DefaultConfig() Config {
	return Config{
		LogDir:   "/var/log/rayxo/domains",
		NginxDir: "/etc/nginx/rayxo",
		GoAccess: runGoAccess,
	}
}

// runGoAccess makes the report as nobody, in a folder of its own: the log
// lines come from visitors, so GoAccess never parses them with more than
// nobody's rights. It only reads stdin and writes the report there.
func runGoAccess(ctx context.Context, input io.Reader, title string, report io.Writer) error {
	nobody, err := user.Lookup("nobody")
	if err != nil {
		return fmt.Errorf("look up nobody: %w", err)
	}

	uid, _ := strconv.ParseUint(nobody.Uid, 10, 32)
	gid, _ := strconv.ParseUint(nobody.Gid, 10, 32)

	dir, err := os.MkdirTemp("", "rayxo-goaccess-")
	if err != nil {
		return err
	}
	defer os.RemoveAll(dir)

	if err := os.Chown(dir, int(uid), int(gid)); err != nil {
		return err
	}

	output := filepath.Join(dir, "report.html")

	var stderr bytes.Buffer

	cmd := exec.CommandContext(ctx, goaccessProgram, "-",
		"--log-format=COMBINED",
		"--no-global-config",
		"--no-progress",
		"--html-report-title="+title,
		"--output="+output,
	)
	cmd.Stdin = input
	cmd.Stdout = io.Discard
	cmd.Stderr = &stderr
	cmd.Dir = dir
	cmd.Env = []string{"PATH=/usr/bin:/bin", "LANG=C.UTF-8", "HOME=" + dir}
	cmd.SysProcAttr = &syscall.SysProcAttr{
		Credential: &syscall.Credential{Uid: uint32(uid), Gid: uint32(gid), Groups: []uint32{}},
		Setpgid:    true,
	}
	cmd.WaitDelay = 5 * time.Second

	if err := cmd.Run(); err != nil {
		message, _, _ := strings.Cut(strings.TrimSpace(stderr.String()), "\n")

		return fmt.Errorf("goaccess: %w: %s", err, message)
	}

	file, info, err := openLog(output)
	if err != nil {
		return fmt.Errorf("read the report: %w", err)
	}
	defer file.Close()

	if info.Size() > maxReportBytes {
		return fmt.Errorf("the report has %d bytes, more than %d", info.Size(), maxReportBytes)
	}

	_, err = io.Copy(report, file)

	return err
}
