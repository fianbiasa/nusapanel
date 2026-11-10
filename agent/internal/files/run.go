package files

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
	"syscall"
)

// maxHelperOutput limits what the agent reads from one helper: a full
// listing or the content of a file opened in the editor.
const maxHelperOutput = 32 << 20

// Account is whose home an operation runs in, and as whom.
type Account struct {
	UID  int
	GID  int
	Home string
}

// Runner runs operations in the files helper: rayxod itself, started again
// with the account's uid and gid and nothing else.
type Runner struct {
	// Executable is the rayxod binary.
	Executable string
}

// Run runs op with params in account's home and decodes the result into
// result. data, when set, becomes the helper's fd 3. A failure the account
// should see is an *Error.
func (r Runner) Run(ctx context.Context, account Account, op string, params any, data *os.File, result any) error {
	encodedParams, err := json.Marshal(params)
	if err != nil {
		return fmt.Errorf("encode params of %s: %w", op, err)
	}

	request, err := json.Marshal(Request{Op: op, Home: account.Home, Params: encodedParams})
	if err != nil {
		return fmt.Errorf("encode request %s: %w", op, err)
	}

	args := []string{HelperArg}
	if data != nil {
		args = append(args, DataArg)
	}

	var stdout, stderr limitedBuffer
	stdout.limit, stderr.limit = maxHelperOutput, 4096

	cmd := exec.CommandContext(ctx, r.Executable, args...)
	cmd.Dir = "/"
	cmd.Env = []string{"PATH=/usr/bin:/bin", "LC_ALL=C"}
	cmd.Stdin = bytes.NewReader(request)
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr
	cmd.SysProcAttr = sysProcAttr(account)

	if data != nil {
		cmd.ExtraFiles = []*os.File{data}
	}

	if err := cmd.Run(); err != nil {
		return fmt.Errorf("files helper %s for %s: %w: %s", op, account.Home, err, strings.TrimSpace(stderr.String()))
	}

	var reply struct {
		Result json.RawMessage `json:"result"`
		Error  *Error          `json:"error"`
	}

	if err := json.Unmarshal(stdout.Bytes(), &reply); err != nil {
		return fmt.Errorf("files helper %s: unreadable reply: %w", op, err)
	}

	if reply.Error != nil {
		return reply.Error
	}

	if result == nil {
		return nil
	}

	return json.Unmarshal(reply.Result, result)
}

// limitedBuffer keeps at most limit bytes and fails writes past it, which
// stops a helper that writes too much.
type limitedBuffer struct {
	bytes.Buffer
	limit int
}

func (b *limitedBuffer) Write(p []byte) (int, error) {
	if b.Len()+len(p) > b.limit {
		return 0, errors.New("files helper wrote too much")
	}

	return b.Buffer.Write(p)
}

// transferIDPattern is the id the panel gives an upload or a download.
var transferIDPattern = regexp.MustCompile(`^[A-Za-z0-9]{32,64}$`)

// Transfers are the files uploads and downloads pass through, in a folder
// root owns and the panel user may write to: the panel writes an upload to
// <id>.upload, and reads a download from <id>.download.
type Transfers struct {
	Dir string
	// PanelUID is the panel user, who writes uploads and reads downloads.
	PanelUID int
}

// CheckTransferID checks the id of an upload or a download.
func CheckTransferID(id string) error {
	if !transferIDPattern.MatchString(id) {
		return fmt.Errorf("transfer id must be 32 to 64 letters and digits")
	}

	return nil
}

// OpenUpload opens the upload id for reading. It only opens a regular file
// the panel user owns, never a link, so the panel user cannot make the
// agent read anything else.
func (t Transfers) OpenUpload(id string) (*os.File, error) {
	if err := t.checkDir(); err != nil {
		return nil, err
	}

	file, err := os.OpenFile(filepath.Join(t.Dir, id+".upload"), os.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_NONBLOCK, 0)
	if err != nil {
		return nil, fmt.Errorf("open upload %s: %w", id, err)
	}

	info, err := file.Stat()
	if err == nil {
		err = t.checkUpload(info)
	}

	if err != nil {
		_ = file.Close()

		return nil, fmt.Errorf("upload %s: %w", id, err)
	}

	return file, nil
}

func (t Transfers) checkUpload(info fs.FileInfo) error {
	if !info.Mode().IsRegular() {
		return errors.New("not a regular file")
	}

	stat, ok := info.Sys().(*syscall.Stat_t)
	if !ok {
		return errors.New("no owner information")
	}

	if uint64(stat.Nlink) != 1 {
		return errors.New("the file has more than one name")
	}

	if os.Geteuid() == 0 && int(stat.Uid) != t.PanelUID {
		return errors.New("not written by the panel")
	}

	return nil
}

// CreateDownload creates the download id, which only the panel user may
// read. It never follows a link or reuses a file.
func (t Transfers) CreateDownload(id string) (*os.File, error) {
	if err := t.checkDir(); err != nil {
		return nil, err
	}

	file, err := os.OpenFile(t.DownloadPath(id), os.O_WRONLY|os.O_CREATE|os.O_EXCL|syscall.O_NOFOLLOW, 0o600)
	if err != nil {
		return nil, fmt.Errorf("create download %s: %w", id, err)
	}

	if os.Geteuid() == 0 {
		if err := file.Chown(t.PanelUID, -1); err != nil {
			_ = file.Close()
			_ = os.Remove(t.DownloadPath(id))

			return nil, fmt.Errorf("give download %s to the panel: %w", id, err)
		}
	}

	return file, nil
}

// DownloadPath is where download id is written.
func (t Transfers) DownloadPath(id string) string {
	return filepath.Join(t.Dir, id+".download")
}

// checkDir makes sure the folder belongs to root and nobody else can
// replace it, when the agent runs as root.
func (t Transfers) checkDir() error {
	info, err := os.Lstat(t.Dir)
	if err != nil {
		return fmt.Errorf("transfer folder: %w", err)
	}

	if !info.IsDir() {
		return fmt.Errorf("transfer folder %s is not a folder", t.Dir)
	}

	if os.Geteuid() != 0 {
		return nil
	}

	stat, ok := info.Sys().(*syscall.Stat_t)
	if !ok || stat.Uid != 0 || info.Mode().Perm()&0o002 != 0 {
		return fmt.Errorf("transfer folder %s must belong to root and not be writable by everyone", t.Dir)
	}

	return nil
}

func setUmask() {
	syscall.Umask(0o022)
}
