package actions

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"regexp"
	"strings"

	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/sysuser"
	"rayxo/agent/internal/validate"
)

const (
	// maxFTPDirectory and maxFTPDepth bound the folder of an FTP account,
	// relative to the home directory.
	maxFTPDirectory = 200
	maxFTPDepth     = 16
	// ftpDirMode is the mode of the folders ftp.ensure_dir creates: like
	// public_html, the web server must be able to read them.
	ftpDirMode = 0o755
)

// ftpDirNamePattern is one folder name of an FTP account's folder.
var ftpDirNamePattern = regexp.MustCompile(`^[A-Za-z0-9._-]+$`)

// ftpSystem is what the ftp.* actions need from the system.
// sysuser.System implements it.
type ftpSystem interface {
	EnsurePath(ctx context.Context, path sysuser.Path) error
}

// ftpDirParams are the params of ftp.ensure_dir: the folder of an FTP
// account, relative to the account's home ("" is the home itself).
type ftpDirParams struct {
	Username  string `json:"username"`
	Directory string `json:"directory"`
}

func (p ftpDirParams) account() string { return p.Username }

func (p ftpDirParams) validate() error {
	if err := validate.Username(p.Username); err != nil {
		return err
	}

	_, err := ftpDirectory(p.Directory)

	return err
}

// ftpDirectory splits directory, a folder relative to a home, into its
// folder names. It refuses anything that could leave the home.
func ftpDirectory(directory string) ([]string, error) {
	if directory == "" {
		return nil, nil
	}

	if len(directory) > maxFTPDirectory {
		return nil, fmt.Errorf("directory may have at most %d characters", maxFTPDirectory)
	}

	names := strings.Split(directory, "/")
	if len(names) > maxFTPDepth {
		return nil, fmt.Errorf("directory may be at most %d folders deep", maxFTPDepth)
	}

	for _, name := range names {
		if name == "." || name == ".." || !ftpDirNamePattern.MatchString(name) {
			return nil, fmt.Errorf("directory %q may only hold folder names of letters, digits, dots, dashes and underscores, separated by single slashes", directory)
		}
	}

	return names, nil
}

// ftpFolder is the result of ftp.ensure_dir: whose the folder is, which the
// panel needs to let FTP logins act as the account.
type ftpFolder struct {
	UID  int    `json:"uid"`
	GID  int    `json:"gid"`
	Home string `json:"home"`
}

// ftpActions implements the ftp.* methods.
type ftpActions struct {
	accounts accountActions
	system   ftpSystem
}

// ftpMethods returns the ftp.* methods by name. FTP logins themselves are
// read by Pure-FTPd straight from the panel's database; the agent only
// prepares their folders.
func ftpMethods(accounts accountSystem, system ftpSystem) map[string]rpc.Method {
	actions := ftpActions{accounts: accountActions{system: accounts}, system: system}

	return map[string]rpc.Method{
		"ftp.ensure_dir": {Handler: actions.ensureDir, LockKey: webLockKey[ftpDirParams]},
	}
}

// ensureDir implements ftp.ensure_dir: it creates the missing folders of an
// FTP account's folder, as the account's, and reports the account's ids.
// Folders that exist are left as they are.
func (f ftpActions) ensureDir(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[ftpDirParams](raw)
	if err != nil {
		return nil, err
	}

	user, err := f.accounts.requireAccount(ctx, params.Username)
	if err != nil {
		return nil, err
	}

	names, _ := ftpDirectory(params.Directory)

	err = f.system.EnsurePath(ctx, sysuser.Path{Home: user.Home, Dirs: names, UID: user.UID, GID: user.GID, Mode: ftpDirMode})
	if errors.Is(err, sysuser.ErrInTheWay) {
		return nil, rpc.Errorf(rpc.CodeActionFailed, "a file or link is in the way of folder %s", params.Directory)
	}

	if err != nil {
		return nil, systemError(err)
	}

	return ftpFolder{UID: user.UID, GID: user.GID, Home: user.Home}, nil
}
