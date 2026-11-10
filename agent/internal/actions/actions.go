// Package actions registers every method the agent exposes. Each method
// validates its own params; nothing here runs arbitrary commands.
package actions

import (
	"context"
	"encoding/json"

	"rayxo/agent/internal/database"
	"rayxo/agent/internal/quota"
	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/sysinfo"
	"rayxo/agent/internal/sysuser"
	"rayxo/agent/internal/version"
	"rayxo/agent/internal/webserver"
)

// Register adds all agent methods to registry.
func Register(registry *rpc.Registry) {
	registry.Register("agent.ping", rpc.Method{Handler: ping})
	registry.Register("system.info", rpc.Method{Handler: systemInfo})

	web := webserver.New(webserver.DefaultConfig())
	quotas := quota.New(quota.DefaultConfig())
	databases := database.New(database.DefaultConfig())

	for name, method := range accountMethods(sysuser.System{}, web, quotas, databases) {
		registry.Register(name, method)
	}

	for name, method := range databaseMethods(sysuser.System{}, databases) {
		registry.Register(name, method)
	}

	for name, method := range webMethods(sysuser.System{}, web) {
		registry.Register(name, method)
	}

	for name, method := range ftpMethods(sysuser.System{}, sysuser.System{}) {
		registry.Register(name, method)
	}

	for name, method := range cronMethods(sysuser.System{}) {
		registry.Register(name, method)
	}
}

// ping lets the panel and the installer check that the agent is up.
func ping(_ context.Context, params json.RawMessage) (any, error) {
	if err := rpc.DecodeParams(params, &struct{}{}); err != nil {
		return nil, err
	}

	return map[string]any{"pong": true, "version": version.Version}, nil
}

// systemInfo reports host, CPU, memory, disk and uptime for the dashboard.
func systemInfo(_ context.Context, params json.RawMessage) (any, error) {
	if err := rpc.DecodeParams(params, &struct{}{}); err != nil {
		return nil, err
	}

	info, err := sysinfo.Collect()
	if err != nil {
		return nil, rpc.Errorf(rpc.CodeActionFailed, "could not read system information: %v", err)
	}

	info.AgentVersion = version.Version

	return info, nil
}
