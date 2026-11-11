package files

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
)

// HelperArg is the first argument that makes rayxod run as the files
// helper, and DataArg the second when it gets the data file as fd 3.
const (
	HelperArg = "files-helper"
	DataArg   = "data"
)

// Operations of the helper.
const (
	OpList       = "list"
	OpRead       = "read"
	OpWrite      = "write"
	OpCreateFile = "create_file"
	OpMkdir      = "mkdir"
	OpRename     = "rename"
	OpCopy       = "copy"
	OpMove       = "move"
	OpDelete     = "delete"
	// OpRemove deletes for good, without the trash; the agent uses it for
	// the mail of deleted mailboxes and mail domains.
	OpRemove     = "remove"
	OpChmod      = "chmod"
	OpUpload     = "upload"
	OpDownload   = "download"
	OpTrashList  = "trash_list"
	OpRestore    = "restore"
	OpPurge      = "purge"
	OpEmptyTrash = "empty_trash"
	OpCompress   = "compress"
	OpExtract    = "extract"
	OpSearch     = "search"
	OpDiskUsage  = "disk_usage"
)

// Request is what the agent sends the helper on stdin.
type Request struct {
	Op     string          `json:"op"`
	Home   string          `json:"home"`
	Params json.RawMessage `json:"params"`
}

// response is what the helper writes to stdout.
type response struct {
	Result any    `json:"result,omitempty"`
	Error  *Error `json:"error,omitempty"`
}

// PathParams name one path.
type PathParams struct {
	Path string `json:"path"`
}

// Check checks the path.
func (p PathParams) Check() error {
	return CheckPath(p.Path)
}

// WriteParams are a file saved from the editor. Version is what reading it
// returned, or "" for a new file.
type WriteParams struct {
	Path    string `json:"path"`
	Content string `json:"content"`
	Version string `json:"version"`
}

// Check checks the path and size.
func (p WriteParams) Check() error {
	if len(p.Content) > MaxEditBytes {
		return errorf(ReasonTooLarge, "a file saved from the editor may have at most %d MB", MaxEditBytes>>20)
	}

	return CheckPath(p.Path)
}

// RenameParams give one path a new name in its folder.
type RenameParams struct {
	Path string `json:"path"`
	Name string `json:"name"`
}

// Check checks the path and the new name.
func (p RenameParams) Check() error {
	if err := CheckPath(p.Path); err != nil {
		return err
	}

	return CheckName(p.Name)
}

// PathsParams name several paths.
type PathsParams struct {
	Paths []string `json:"paths"`
}

// Check checks every path.
func (p PathsParams) Check() error {
	return checkPaths(p.Paths)
}

// TransferParams copy or move paths into a folder.
type TransferParams struct {
	Paths       []string `json:"paths"`
	Destination string   `json:"destination"`
}

// Check checks the paths and the folder.
func (p TransferParams) Check() error {
	if err := checkPaths(p.Paths); err != nil {
		return err
	}

	return CheckPath(p.Destination)
}

// ChmodParams set the permission bits (0 to 0777) of paths.
type ChmodParams struct {
	Paths []string `json:"paths"`
	Mode  uint32   `json:"mode"`
}

// Check checks the paths and the mode.
func (p ChmodParams) Check() error {
	if p.Mode > 0o777 {
		return errorf(ReasonInvalid, "mode %o is not a permission between 0000 and 0777", p.Mode)
	}

	return checkPaths(p.Paths)
}

// UploadParams store the data file as Path.
type UploadParams struct {
	Path      string `json:"path"`
	Overwrite bool   `json:"overwrite"`
}

// Check checks the path.
func (p UploadParams) Check() error {
	return CheckPath(p.Path)
}

// TrashParams name deleted items.
type TrashParams struct {
	IDs []string `json:"ids"`
}

// Check checks the ids.
func (p TrashParams) Check() error {
	if len(p.IDs) == 0 || len(p.IDs) > MaxItems {
		return errorf(ReasonInvalid, "name between 1 and %d deleted items", MaxItems)
	}

	for _, id := range p.IDs {
		if err := CheckTrashID(id); err != nil {
			return err
		}
	}

	return nil
}

// CompressParams pack paths into a new archive at Destination, in Format
// (zip or tar.gz).
type CompressParams struct {
	Paths       []string `json:"paths"`
	Destination string   `json:"destination"`
	Format      string   `json:"format"`
}

// Check checks the paths, the archive and its format.
func (p CompressParams) Check() error {
	if p.Format != FormatZip && p.Format != FormatTarGz {
		return errorf(ReasonInvalid, "format must be %s or %s", FormatZip, FormatTarGz)
	}

	if p.Destination == "" {
		return errorf(ReasonInvalid, "name the archive")
	}

	if err := checkPaths(p.Paths); err != nil {
		return err
	}

	return CheckPath(p.Destination)
}

// ExtractParams unpack the archive Path into folder Destination.
type ExtractParams struct {
	Path        string `json:"path"`
	Destination string `json:"destination"`
	Overwrite   bool   `json:"overwrite"`
}

// Check checks the archive and the folder.
func (p ExtractParams) Check() error {
	if p.Path == "" {
		return errorf(ReasonInvalid, "name the archive")
	}

	if err := CheckPath(p.Path); err != nil {
		return err
	}

	return CheckPath(p.Destination)
}

// SearchParams look for Query in the names under folder Path.
type SearchParams struct {
	Path  string `json:"path"`
	Query string `json:"query"`
}

// Check checks the folder and the text.
func (p SearchParams) Check() error {
	if err := CheckQuery(p.Query); err != nil {
		return err
	}

	return CheckPath(p.Path)
}

// NoParams are the params of operations on the whole trash.
type NoParams struct{}

// Check has nothing to check.
func (NoParams) Check() error {
	return nil
}

func checkPaths(paths []string) error {
	if len(paths) == 0 || len(paths) > MaxItems {
		return errorf(ReasonInvalid, "name between 1 and %d paths", MaxItems)
	}

	for _, p := range paths {
		if err := CheckPath(p); err != nil {
			return err
		}
	}

	return nil
}

// checker is implemented by every params type.
type checker interface {
	Check() error
}

// Execute runs operation op in home. data is the file an upload is read
// from, or a download written to.
func Execute(home, op string, raw json.RawMessage, data *os.File) (any, error) {
	h, err := OpenHome(home)
	if err != nil {
		return nil, fail(err, "")
	}
	defer h.Close()

	switch op {
	case OpList:
		return run(raw, func(p PathParams) (any, error) { return h.List(p.Path) })
	case OpRead:
		return run(raw, func(p PathParams) (any, error) { return h.Read(p.Path) })
	case OpWrite:
		return run(raw, func(p WriteParams) (any, error) { return h.Write(p.Path, p.Content, p.Version) })
	case OpCreateFile:
		return run(raw, func(p PathParams) (any, error) { return struct{}{}, h.CreateFile(p.Path) })
	case OpMkdir:
		return run(raw, func(p PathParams) (any, error) { return struct{}{}, h.Mkdir(p.Path) })
	case OpRename:
		return run(raw, func(p RenameParams) (any, error) { return struct{}{}, h.Rename(p.Path, p.Name) })
	case OpCopy:
		return run(raw, func(p TransferParams) (any, error) { return h.Copy(p.Paths, p.Destination), nil })
	case OpMove:
		return run(raw, func(p TransferParams) (any, error) { return h.Move(p.Paths, p.Destination), nil })
	case OpDelete:
		return run(raw, func(p PathsParams) (any, error) { return h.Delete(p.Paths), nil })
	case OpRemove:
		return run(raw, func(p PathsParams) (any, error) { return h.Remove(p.Paths), nil })
	case OpChmod:
		return run(raw, func(p ChmodParams) (any, error) { return h.Chmod(p.Paths, fs.FileMode(p.Mode)), nil })
	case OpUpload:
		return run(raw, func(p UploadParams) (any, error) {
			if data == nil {
				return nil, errorf(ReasonInvalid, "the upload has no data")
			}

			return h.Upload(data, p.Path, p.Overwrite)
		})
	case OpDownload:
		return run(raw, func(p PathParams) (any, error) {
			if data == nil {
				return nil, errorf(ReasonInvalid, "the download has nowhere to go")
			}

			return h.Download(data, p.Path)
		})
	case OpTrashList:
		return run(raw, func(NoParams) (any, error) {
			items, err := h.TrashList()

			return map[string]any{"items": items}, err
		})
	case OpRestore:
		return run(raw, func(p TrashParams) (any, error) { return h.Restore(p.IDs), nil })
	case OpPurge:
		return run(raw, func(p TrashParams) (any, error) { return h.Purge(p.IDs), nil })
	case OpEmptyTrash:
		return run(raw, func(NoParams) (any, error) { return h.EmptyTrash(), nil })
	case OpCompress:
		return run(raw, func(p CompressParams) (any, error) { return h.Compress(p.Paths, p.Destination, p.Format) })
	case OpExtract:
		return run(raw, func(p ExtractParams) (any, error) { return h.Extract(p.Path, p.Destination, p.Overwrite) })
	case OpSearch:
		return run(raw, func(p SearchParams) (any, error) { return h.Search(p.Path, p.Query) })
	case OpDiskUsage:
		return run(raw, func(p PathsParams) (any, error) { return h.DiskUsage(p.Paths), nil })
	}

	return nil, errorf(ReasonInvalid, "unknown operation %q", op)
}

// run strictly decodes and checks the params of an operation, then does it.
func run[P checker](raw json.RawMessage, do func(P) (any, error)) (any, error) {
	var params P

	if len(bytes.TrimSpace(raw)) > 0 {
		decoder := json.NewDecoder(bytes.NewReader(raw))
		decoder.DisallowUnknownFields()

		if err := decoder.Decode(&params); err != nil {
			return nil, errorf(ReasonInvalid, "invalid params: %v", err)
		}
	}

	if err := params.Check(); err != nil {
		return nil, err
	}

	return do(params)
}

// Helper reads one Request from in, runs it, and writes the result or the
// *Error to out. Other errors mean the helper itself failed.
func Helper(in io.Reader, out io.Writer, data *os.File) error {
	var request Request

	decoder := json.NewDecoder(io.LimitReader(in, 16*MaxEditBytes))
	decoder.DisallowUnknownFields()

	if err := decoder.Decode(&request); err != nil {
		return fmt.Errorf("read request: %w", err)
	}

	result, err := Execute(request.Home, request.Op, request.Params, data)

	var reply response

	if err != nil {
		var known *Error
		if !errors.As(err, &known) {
			return err
		}

		reply.Error = known
	} else {
		reply.Result = result
	}

	return json.NewEncoder(out).Encode(reply)
}

// HelperMain is the helper process. It never runs as root: the agent starts
// it with the account's uid and gid. It returns the exit status.
func HelperMain(args []string) int {
	if os.Geteuid() == 0 {
		fmt.Fprintln(os.Stderr, "the files helper must not run as root")

		return 2
	}

	setUmask()

	var data *os.File
	if len(args) > 0 && args[0] == DataArg {
		data = os.NewFile(3, "data")
	}

	if err := Helper(os.Stdin, os.Stdout, data); err != nil {
		fmt.Fprintln(os.Stderr, err)

		return 1
	}

	return 0
}
