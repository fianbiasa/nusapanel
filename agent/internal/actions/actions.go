// Package actions registers every method the agent exposes. Each method
// validates its own params; nothing here runs arbitrary commands.
package actions

import (
	"context"
	"encoding/json"

	"rayxo/agent/internal/bandwidth"
	"rayxo/agent/internal/cgroup"
	"rayxo/agent/internal/clock"
	"rayxo/agent/internal/database"
	"rayxo/agent/internal/dns"
	"rayxo/agent/internal/files"
	"rayxo/agent/internal/jail"
	"rayxo/agent/internal/mail"
	"rayxo/agent/internal/mailqueue"
	"rayxo/agent/internal/panelport"
	"rayxo/agent/internal/procmon"
	"rayxo/agent/internal/quota"
	"rayxo/agent/internal/reconfigure"
	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/runner"
	"rayxo/agent/internal/services"
	"rayxo/agent/internal/sitelogs"
	"rayxo/agent/internal/sysfiles"
	"rayxo/agent/internal/sysinfo"
	"rayxo/agent/internal/sysuser"
	"rayxo/agent/internal/version"
	"rayxo/agent/internal/webserver"
)

// Config is the agent's setup that methods need.
type Config struct {
	Files FilesConfig
	// Services also runs the watchdog; nil makes one for real systemd.
	Services *services.Manager
	// Reconfigure runs setup.sh again, and PanelPort tries panel ports;
	// rayxod also watches them. nil makes them for the real server.
	Reconfigure *reconfigure.Manager
	PanelPort   *panelport.Manager
}

// Register adds all agent methods to registry.
func Register(registry *rpc.Registry, config Config) {
	registry.Register("agent.ping", rpc.Method{Handler: ping})
	registry.Register("system.info", rpc.Method{Handler: systemInfo})

	web := webserver.New(webserver.DefaultConfig())
	quotas := quota.New(quota.DefaultConfig())
	databases := database.New(database.DefaultConfig())
	jails := jail.New(jail.DefaultConfig())
	limits := cgroup.New(cgroup.Config{UnitDir: "/etc/systemd/system", Root: "/sys/fs/cgroup", Run: runner.Run})

	for name, method := range accountMethods(sysuser.System{}, web, quotas, databases, jails, limits) {
		registry.Register(name, method)
	}

	for name, method := range resourceMethods(sysuser.System{}, limits) {
		registry.Register(name, method)
	}

	for name, method := range webMethods(sysuser.System{}, web) {
		registry.Register(name, method)
	}

	changer := config.Reconfigure
	if changer == nil {
		changer = reconfigure.New(reconfigure.DefaultConfig())
	}

	ports := config.PanelPort
	if ports == nil {
		ports = panelport.New(panelport.DefaultConfig())
	}

	for name, method := range systemMethods(web, clock.New(clock.DefaultConfig()), changer, ports) {
		registry.Register(name, method)
	}

	for name, method := range ftpMethods(sysuser.System{}, sysuser.System{}) {
		registry.Register(name, method)
	}

	for name, method := range cronMethods(sysuser.System{}) {
		registry.Register(name, method)
	}

	for name, method := range dnsMethods(dns.New(dns.DefaultConfig())) {
		registry.Register(name, method)
	}

	for name, method := range usageMethods(bandwidth.New(bandwidth.DefaultConfig())) {
		registry.Register(name, method)
	}

	helper := files.Runner{Executable: config.Files.Executable}
	transfers := files.Transfers{Dir: config.Files.TransferDir, PanelUID: config.Files.PanelUID}

	mailConfig := mail.DefaultConfig()
	mailConfig.Remove = mail.RemoveWith(helper)
	mailConfig.Write = mail.WriteWith(helper)

	for name, method := range mailMethods(sysuser.System{}, mail.New(mailConfig), web) {
		registry.Register(name, method)
	}

	for name, method := range mailQueueMethods(mailqueue.New(mailqueue.DefaultConfig())) {
		registry.Register(name, method)
	}

	for name, method := range fileMethods(sysuser.System{}, helper, transfers) {
		registry.Register(name, method)
	}

	for name, method := range databaseMethods(sysuser.System{}, databases, transfers, helper) {
		registry.Register(name, method)
	}

	for name, method := range backupMethods(sysuser.System{}, helper, databases) {
		registry.Register(name, method)
	}

	for name, method := range sshMethods(sysuser.System{}, jails, helper) {
		registry.Register(name, method)
	}

	for name, method := range logMethods(sysuser.System{}, sitelogs.New(sitelogs.DefaultConfig()), transfers) {
		registry.Register(name, method)
	}

	files := sysfiles.DefaultConfig()
	files.PHPVersions = web.PHPVersions
	files.Run = runner.Run

	for name, method := range configFileMethods(sysfiles.New(files)) {
		registry.Register(name, method)
	}

	for name, method := range loginMethods(web) {
		registry.Register(name, method)
	}

	for name, method := range networkMethods(defaultServerAddresses()) {
		registry.Register(name, method)
	}

	for name, method := range processMethods(sysuser.System{}, procmon.New(procmon.DefaultConfig())) {
		registry.Register(name, method)
	}

	manager := config.Services
	if manager == nil {
		manager = services.New(services.DefaultConfig())
	}

	for name, method := range serviceMethods(manager) {
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
