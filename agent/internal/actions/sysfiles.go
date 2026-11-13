package actions

import (
	"context"
	"encoding/json"
	"errors"

	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/sysfiles"
)

// editableFiles are the configuration files the administrator edits.
// *sysfiles.Manager implements it.
type editableFiles interface {
	Files() ([]sysfiles.File, error)
	Read(id string) (sysfiles.Content, error)
	Write(ctx context.Context, id, content, hash string) (string, error)
	Restore(ctx context.Context, id, backup, hash string) (string, error)
}

type configFileActions struct {
	files editableFiles
}

// configFileMethods returns the system.file* methods; changes take one lock.
func configFileMethods(files editableFiles) map[string]rpc.Method {
	f := configFileActions{files: files}
	lock := func(json.RawMessage) (string, error) { return "system-files", nil }

	return map[string]rpc.Method{
		"system.files":        {Handler: f.list},
		"system.file_read":    {Handler: f.read},
		"system.file_write":   {Handler: f.write, LockKey: lock},
		"system.file_restore": {Handler: f.restore, LockKey: lock},
	}
}

// list implements system.files: the IDs and paths of the editable files.
func (f configFileActions) list(_ context.Context, raw json.RawMessage) (any, error) {
	if err := rpc.DecodeParams(raw, &struct{}{}); err != nil {
		return nil, err
	}

	files, err := f.files.Files()
	if err != nil {
		return nil, err
	}

	return struct {
		Files []sysfiles.File `json:"files"`
	}{Files: files}, nil
}

// read implements system.file_read {file}.
func (f configFileActions) read(_ context.Context, raw json.RawMessage) (any, error) {
	var params struct {
		File string `json:"file"`
	}
	if err := rpc.DecodeParams(raw, &params); err != nil {
		return nil, err
	}

	content, err := f.files.Read(params.File)
	if err != nil {
		return nil, configFileError(err)
	}

	return content, nil
}

// write implements system.file_write {file, content, hash}: hash is what
// system.file_read returned, so a change made meanwhile is not lost.
func (f configFileActions) write(ctx context.Context, raw json.RawMessage) (any, error) {
	var params struct {
		File    string `json:"file"`
		Content string `json:"content"`
		Hash    string `json:"hash"`
	}
	if err := rpc.DecodeParams(raw, &params); err != nil {
		return nil, err
	}

	hash, err := f.files.Write(ctx, params.File, params.Content, params.Hash)
	if err != nil {
		return nil, configFileError(err)
	}

	return struct {
		Hash string `json:"hash"`
	}{Hash: hash}, nil
}

// restore implements system.file_restore {file, backup, hash}.
func (f configFileActions) restore(ctx context.Context, raw json.RawMessage) (any, error) {
	var params struct {
		File   string `json:"file"`
		Backup string `json:"backup"`
		Hash   string `json:"hash"`
	}
	if err := rpc.DecodeParams(raw, &params); err != nil {
		return nil, err
	}

	hash, err := f.files.Restore(ctx, params.File, params.Backup, params.Hash)
	if err != nil {
		return nil, configFileError(err)
	}

	return struct {
		Hash string `json:"hash"`
	}{Hash: hash}, nil
}

// configFileError explains what the administrator can act on.
func configFileError(err error) error {
	if invalid, ok := errors.AsType[*sysfiles.InvalidError](err); ok {
		return &rpc.Error{Code: rpc.CodeActionFailed, Message: invalid.Reason, Data: map[string]string{"reason": "invalid"}}
	}

	switch {
	case errors.Is(err, sysfiles.ErrUnknownFile):
		return rpc.Errorf(rpc.CodeInvalidParams, "invalid params: no such editable file")
	case errors.Is(err, sysfiles.ErrChanged):
		return &rpc.Error{Code: rpc.CodeActionFailed, Message: "the file changed since it was opened; open it again", Data: map[string]string{"reason": "changed"}}
	case errors.Is(err, sysfiles.ErrNoBackup):
		return rpc.Errorf(rpc.CodeActionFailed, "no such backup")
	}

	return err
}
