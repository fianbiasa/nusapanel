package actions

import (
	"context"
	"encoding/json"
	"regexp"

	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/services"
)

// serviceUnitPattern is the name of a systemd service unit.
var serviceUnitPattern = regexp.MustCompile(`^[a-z0-9][a-z0-9@._-]{0,99}\.service$`)

// serviceManager shows and controls system services. services.Manager
// implements it.
type serviceManager interface {
	Status(ctx context.Context) (services.Report, error)
	Act(ctx context.Context, unit, action string) error
}

type serviceActionParams struct {
	Unit   string `json:"unit"`
	Action string `json:"action"`
}

// serviceMethods returns the service.* methods by name. Which services can
// be controlled, and how, is decided by the services catalog.
func serviceMethods(manager serviceManager) map[string]rpc.Method {
	return map[string]rpc.Method{
		"service.status": {Handler: func(ctx context.Context, raw json.RawMessage) (any, error) {
			if err := rpc.DecodeParams(raw, &struct{}{}); err != nil {
				return nil, err
			}

			report, err := manager.Status(ctx)
			if err != nil {
				return nil, systemError(err)
			}

			return report, nil
		}},
		"service.action": {
			Handler: func(ctx context.Context, raw json.RawMessage) (any, error) {
				var params serviceActionParams
				if err := rpc.DecodeParams(raw, &params); err != nil {
					return nil, err
				}

				if !serviceUnitPattern.MatchString(params.Unit) {
					return nil, rpc.Errorf(rpc.CodeInvalidParams, "invalid params: %q is not a service unit", params.Unit)
				}

				if err := manager.Act(ctx, params.Unit, params.Action); err != nil {
					if refused, ok := services.AsError(err); ok {
						return nil, &rpc.Error{Code: rpc.CodeActionFailed, Message: refused.Message, Data: map[string]string{"reason": refused.Reason}}
					}

					return nil, systemError(err)
				}

				return map[string]any{"unit": params.Unit, "action": params.Action}, nil
			},
			// One change of a service at a time.
			LockKey: func(raw json.RawMessage) (string, error) {
				var params serviceActionParams
				if err := rpc.DecodeParams(raw, &params); err != nil {
					return "", err
				}

				return "service:" + params.Unit, nil
			},
		},
	}
}
