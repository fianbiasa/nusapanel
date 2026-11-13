package actions

import (
	"context"
	"encoding/json"
	"errors"

	"rayxo/agent/internal/procmon"
	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/validate"
)

// processMonitor lists and stops processes. *procmon.Manager implements it.
type processMonitor interface {
	List(uid int) (procmon.Snapshot, error)
	Kill(pid int, start, signal string) error
}

// processListParams are the params of process.list: every process, or
// with a username only the account's.
type processListParams struct {
	Username string `json:"username"`
}

// processKillParams are the params of process.kill. Start is what
// process.list returned for the process, so a PID used again meanwhile is
// left alone.
type processKillParams struct {
	PID    int    `json:"pid"`
	Start  string `json:"start"`
	Signal string `json:"signal"`
}

type processActions struct {
	accounts accountActions
	monitor  processMonitor
}

// processMethods returns the process monitor's methods. Neither changes an
// account, so they take no lock.
func processMethods(system accountSystem, monitor processMonitor) map[string]rpc.Method {
	p := processActions{accounts: accountActions{system: system}, monitor: monitor}

	return map[string]rpc.Method{
		"process.list": {Handler: p.list},
		"process.kill": {Handler: p.kill},
	}
}

// list implements process.list.
func (p processActions) list(ctx context.Context, raw json.RawMessage) (any, error) {
	var params processListParams
	if err := rpc.DecodeParams(raw, &params); err != nil {
		return nil, err
	}

	uid := -1

	if params.Username != "" {
		if err := validate.Username(params.Username); err != nil {
			return nil, rpc.Errorf(rpc.CodeInvalidParams, "invalid params: %v", err)
		}

		user, err := p.accounts.requireAccount(ctx, params.Username)
		if err != nil {
			return nil, err
		}

		uid = user.UID
	}

	snapshot, err := p.monitor.List(uid)
	if err != nil {
		return nil, err
	}

	return snapshot, nil
}

// kill implements process.kill.
func (p processActions) kill(_ context.Context, raw json.RawMessage) (any, error) {
	var params processKillParams
	if err := rpc.DecodeParams(raw, &params); err != nil {
		return nil, err
	}

	if _, ok := procmon.Signals[params.Signal]; !ok || params.PID < 1 || params.Start == "" {
		return nil, rpc.Errorf(rpc.CodeInvalidParams, "invalid params: pid, start and signal TERM or KILL are required")
	}

	err := p.monitor.Kill(params.PID, params.Start, params.Signal)

	switch {
	case errors.Is(err, procmon.ErrGone):
		return nil, &rpc.Error{Code: rpc.CodeActionFailed, Message: "the process has ended", Data: map[string]string{"reason": "gone"}}
	case errors.Is(err, procmon.ErrProtected):
		return nil, &rpc.Error{Code: rpc.CodeActionFailed, Message: "this process may not be stopped", Data: map[string]string{"reason": "protected"}}
	case err != nil:
		return nil, err
	}

	return struct{}{}, nil
}
