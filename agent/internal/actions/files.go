package actions

import (
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"os"

	"rayxo/agent/internal/files"
	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/validate"
)

// FilesConfig is what the fs.* methods need from the agent's setup.
type FilesConfig struct {
	// Executable is the rayxod binary, which runs again as the files helper.
	Executable string
	// TransferDir holds uploads and downloads on their way between the
	// panel and a home.
	TransferDir string
	// PanelUID is the panel user, who writes uploads and reads downloads.
	PanelUID int
}

// fileHelper runs an operation in an account's home, as the account.
// files.Runner implements it.
type fileHelper interface {
	Run(ctx context.Context, account files.Account, op string, params any, data *os.File, result any) error
}

// fileTransfers opens uploads and creates downloads. files.Transfers
// implements it.
type fileTransfers interface {
	OpenUpload(id string) (*os.File, error)
	CreateDownload(id string) (*os.File, error)
	DownloadPath(id string) string
}

// fileActions implements the fs.* methods: the file manager. The agent
// itself never touches a home's files; the helper does, as the account.
type fileActions struct {
	accounts  accountActions
	helper    fileHelper
	transfers fileTransfers
}

// fsParams are the params of an fs.* method: the account, and the params of
// the helper operation it runs.
type fsParams interface {
	webParams
	payload() any
}

type checkable interface{ Check() error }

func checkFS(username string, params checkable) error {
	if err := validate.Username(username); err != nil {
		return err
	}

	return params.Check()
}

type fsPath struct {
	Username string `json:"username"`
	files.PathParams
}

func (p fsPath) account() string { return p.Username }
func (p fsPath) validate() error { return checkFS(p.Username, p.PathParams) }
func (p fsPath) payload() any    { return p.PathParams }

type fsWrite struct {
	Username string `json:"username"`
	files.WriteParams
}

func (p fsWrite) account() string { return p.Username }
func (p fsWrite) validate() error { return checkFS(p.Username, p.WriteParams) }
func (p fsWrite) payload() any    { return p.WriteParams }

type fsRename struct {
	Username string `json:"username"`
	files.RenameParams
}

func (p fsRename) account() string { return p.Username }
func (p fsRename) validate() error { return checkFS(p.Username, p.RenameParams) }
func (p fsRename) payload() any    { return p.RenameParams }

type fsPaths struct {
	Username string `json:"username"`
	files.PathsParams
}

func (p fsPaths) account() string { return p.Username }
func (p fsPaths) validate() error { return checkFS(p.Username, p.PathsParams) }
func (p fsPaths) payload() any    { return p.PathsParams }

type fsTransfer struct {
	Username string `json:"username"`
	files.TransferParams
}

func (p fsTransfer) account() string { return p.Username }
func (p fsTransfer) validate() error { return checkFS(p.Username, p.TransferParams) }
func (p fsTransfer) payload() any    { return p.TransferParams }

type fsChmod struct {
	Username string `json:"username"`
	files.ChmodParams
}

func (p fsChmod) account() string { return p.Username }
func (p fsChmod) validate() error { return checkFS(p.Username, p.ChmodParams) }
func (p fsChmod) payload() any    { return p.ChmodParams }

type fsTrash struct {
	Username string `json:"username"`
	files.TrashParams
}

func (p fsTrash) account() string { return p.Username }
func (p fsTrash) validate() error { return checkFS(p.Username, p.TrashParams) }
func (p fsTrash) payload() any    { return p.TrashParams }

type fsHome struct {
	Username string `json:"username"`
}

func (p fsHome) account() string { return p.Username }
func (p fsHome) validate() error { return validate.Username(p.Username) }
func (p fsHome) payload() any    { return files.NoParams{} }

// fsUpload stores the upload Transfer as Path.
type fsUpload struct {
	Username string `json:"username"`
	Transfer string `json:"transfer"`
	files.UploadParams
}

func (p fsUpload) account() string { return p.Username }
func (p fsUpload) payload() any    { return p.UploadParams }

func (p fsUpload) validate() error {
	if err := files.CheckTransferID(p.Transfer); err != nil {
		return err
	}

	return checkFS(p.Username, p.UploadParams)
}

// fsDownload writes file Path to the download Transfer.
type fsDownload struct {
	Username string `json:"username"`
	Transfer string `json:"transfer"`
	files.PathParams
}

func (p fsDownload) account() string { return p.Username }
func (p fsDownload) payload() any    { return p.PathParams }

func (p fsDownload) validate() error {
	if err := files.CheckTransferID(p.Transfer); err != nil {
		return err
	}

	return checkFS(p.Username, p.PathParams)
}

// fileMethods returns the fs.* methods by name. They take no account lock:
// they only change files, as the account, which the account can do through
// FTP at the same time anyway.
func fileMethods(accounts accountSystem, helper fileHelper, transfers fileTransfers) map[string]rpc.Method {
	f := fileActions{accounts: accountActions{system: accounts}, helper: helper, transfers: transfers}

	return map[string]rpc.Method{
		"fs.list":          fsMethod[fsPath](f, files.OpList),
		"fs.read":          fsMethod[fsPath](f, files.OpRead),
		"fs.write":         fsMethod[fsWrite](f, files.OpWrite),
		"fs.create_file":   fsMethod[fsPath](f, files.OpCreateFile),
		"fs.mkdir":         fsMethod[fsPath](f, files.OpMkdir),
		"fs.rename":        fsMethod[fsRename](f, files.OpRename),
		"fs.copy":          fsMethod[fsTransfer](f, files.OpCopy),
		"fs.move":          fsMethod[fsTransfer](f, files.OpMove),
		"fs.delete":        fsMethod[fsPaths](f, files.OpDelete),
		"fs.chmod":         fsMethod[fsChmod](f, files.OpChmod),
		"fs.trash_list":    fsMethod[fsHome](f, files.OpTrashList),
		"fs.trash_restore": fsMethod[fsTrash](f, files.OpRestore),
		"fs.trash_delete":  fsMethod[fsTrash](f, files.OpPurge),
		"fs.trash_empty":   fsMethod[fsHome](f, files.OpEmptyTrash),
		"fs.upload":        {Handler: f.upload},
		"fs.download":      {Handler: f.download},
	}
}

// fsMethod runs helper operation op with the params of P.
func fsMethod[P fsParams](f fileActions, op string) rpc.Method {
	return rpc.Method{Handler: func(ctx context.Context, raw json.RawMessage) (any, error) {
		params, err := decodeWebParams[P](raw)
		if err != nil {
			return nil, err
		}

		account, err := f.account(ctx, params.account())
		if err != nil {
			return nil, err
		}

		var result json.RawMessage
		if err := f.helper.Run(ctx, account, op, params.payload(), nil, &result); err != nil {
			return nil, fileError(err)
		}

		return result, nil
	}}
}

// upload implements fs.upload: the helper reads the upload the panel wrote,
// which the agent opens and hands over.
func (f fileActions) upload(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[fsUpload](raw)
	if err != nil {
		return nil, err
	}

	account, err := f.account(ctx, params.Username)
	if err != nil {
		return nil, err
	}

	source, err := f.transfers.OpenUpload(params.Transfer)
	if err != nil {
		slog.Warn("upload refused", "transfer", params.Transfer, "error", err)

		return nil, rpc.Errorf(rpc.CodeActionFailed, "the uploaded file could not be read")
	}
	defer source.Close()

	var result json.RawMessage
	if err := f.helper.Run(ctx, account, files.OpUpload, params.payload(), source, &result); err != nil {
		return nil, fileError(err)
	}

	return result, nil
}

// download implements fs.download: the helper writes the file to a new file
// only the panel user can read, which the agent creates and hands over.
func (f fileActions) download(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[fsDownload](raw)
	if err != nil {
		return nil, err
	}

	account, err := f.account(ctx, params.Username)
	if err != nil {
		return nil, err
	}

	target, err := f.transfers.CreateDownload(params.Transfer)
	if err != nil {
		slog.Warn("download refused", "transfer", params.Transfer, "error", err)

		return nil, rpc.Errorf(rpc.CodeActionFailed, "the download could not be prepared")
	}

	var result json.RawMessage
	err = f.helper.Run(ctx, account, files.OpDownload, params.payload(), target, &result)

	if closeErr := target.Close(); err == nil {
		err = closeErr
	}

	if err != nil {
		_ = os.Remove(f.transfers.DownloadPath(params.Transfer))

		return nil, fileError(err)
	}

	return result, nil
}

// account returns whose home a call acts in.
func (f fileActions) account(ctx context.Context, name string) (files.Account, error) {
	user, err := f.accounts.requireAccount(ctx, name)
	if err != nil {
		return files.Account{}, err
	}

	return files.Account{UID: user.UID, GID: user.GID, Home: user.Home}, nil
}

// fileError passes a failure the account should see to the panel, with its
// reason; anything else stays in the agent log.
func fileError(err error) error {
	var failure *files.Error
	if errors.As(err, &failure) {
		return &rpc.Error{
			Code:    rpc.CodeActionFailed,
			Message: failure.Message,
			Data:    map[string]string{"reason": failure.Reason},
		}
	}

	return systemError(err)
}
