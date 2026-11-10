package actions

import (
	"context"
	"encoding/json"
	"errors"

	"rayxo/agent/internal/clock"
	"rayxo/agent/internal/panelport"
	"rayxo/agent/internal/reconfigure"
	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/validate"
	"rayxo/agent/internal/webserver"
)

// hostnameCertificates is the server's own certificate, the one the panel,
// phpMyAdmin and FTP serve. webserver.Manager implements it.
type hostnameCertificates interface {
	ServerHostname() (string, error)
	HostnameCertificateStatus() (webserver.HostnameCertificate, error)
	IssueHostnameCertificate(ctx context.Context) (webserver.HostnameCertificate, error)
	HasWebsite(domain string) (bool, error)
}

// hostnameChanger runs setup.sh again for a new hostname.
// reconfigure.Manager implements it.
type hostnameChanger interface {
	Start(ctx context.Context, request reconfigure.Request) error
	Status(ctx context.Context) (*reconfigure.State, error)
}

// panelPorts moves the panel to another port. panelport.Manager
// implements it.
type panelPorts interface {
	Current() int
	Pending() (*panelport.Trial, error)
	Open(ctx context.Context, port int) (panelport.Trial, error)
	Confirm(ctx context.Context, start func(ctx context.Context, port int) error) (panelport.Trial, error)
	Close(ctx context.Context) error
}

// serverClock is the server's timezone. clock.Manager implements it.
type serverClock interface {
	Timezone() string
	SetTimezone(ctx context.Context, timezone string) error
}

// serverSettings is the result of system.settings. Certificate is nil when
// the panel's certificate cannot be read, Reconfigure when the hostname was
// never changed from the panel.
type serverSettings struct {
	Hostname    string                         `json:"hostname"`
	Timezone    string                         `json:"timezone"`
	Certificate *webserver.HostnameCertificate `json:"certificate"`
	Reconfigure *reconfigure.State             `json:"reconfigure"`
	PanelPort   int                            `json:"panel_port"`
	PortTrial   *panelport.Trial               `json:"port_trial"`
}

type portParams struct {
	Port int `json:"port"`
}

type hostnameParams struct {
	Hostname string `json:"hostname"`
}

type timezoneParams struct {
	Timezone string `json:"timezone"`
}

// systemMethods returns the methods behind the panel's server settings.
func systemMethods(certificates hostnameCertificates, serverTime serverClock, changer hostnameChanger, ports panelPorts) map[string]rpc.Method {
	return map[string]rpc.Method{
		"system.settings": {Handler: func(ctx context.Context, raw json.RawMessage) (any, error) {
			if err := rpc.DecodeParams(raw, &struct{}{}); err != nil {
				return nil, err
			}

			settings := serverSettings{Timezone: serverTime.Timezone()}
			settings.Hostname, _ = certificates.ServerHostname()

			if status, err := certificates.HostnameCertificateStatus(); err == nil {
				settings.Certificate = &status
			}

			state, err := changer.Status(ctx)
			if err != nil {
				return nil, err
			}

			settings.Reconfigure = state
			settings.PanelPort = ports.Current()

			if settings.PortTrial, err = ports.Pending(); err != nil {
				return nil, err
			}

			return settings, nil
		}},
		"panel.port_trial": {Handler: func(ctx context.Context, raw json.RawMessage) (any, error) {
			var params portParams
			if err := rpc.DecodeParams(raw, &params); err != nil {
				return nil, err
			}

			trial, err := ports.Open(ctx, params.Port)
			if err != nil {
				return nil, portError(err, params.Port)
			}

			return trial, nil
		}},
		"panel.port_confirm": {Handler: func(ctx context.Context, raw json.RawMessage) (any, error) {
			if err := rpc.DecodeParams(raw, &struct{}{}); err != nil {
				return nil, err
			}

			trial, err := ports.Confirm(ctx, func(ctx context.Context, port int) error {
				return changer.Start(ctx, reconfigure.Request{PanelPort: port})
			})
			if err != nil {
				return nil, portError(err, 0)
			}

			return trial, nil
		}},
		"panel.port_cancel": {Handler: func(ctx context.Context, raw json.RawMessage) (any, error) {
			if err := rpc.DecodeParams(raw, &struct{}{}); err != nil {
				return nil, err
			}

			if err := ports.Close(ctx); err != nil {
				return nil, err
			}

			return map[string]bool{"closed": true}, nil
		}},
		"system.change_hostname": {Handler: func(ctx context.Context, raw json.RawMessage) (any, error) {
			var params hostnameParams
			if err := rpc.DecodeParams(raw, &params); err != nil {
				return nil, err
			}

			if err := validate.Domain(params.Hostname); err != nil {
				return nil, rpc.Errorf(rpc.CodeInvalidParams, "invalid params: %v", err)
			}

			if current, _ := certificates.ServerHostname(); current == params.Hostname {
				return nil, rpc.Errorf(rpc.CodeActionFailed, "%s is already the hostname of this server", params.Hostname)
			}

			website, err := certificates.HasWebsite(params.Hostname)
			if err != nil {
				return nil, err
			}

			if website {
				return nil, rpc.Errorf(rpc.CodeActionFailed, "%s is the domain of a website; choose a hostname no account uses", params.Hostname)
			}

			if err := changer.Start(ctx, reconfigure.Request{Hostname: params.Hostname}); err != nil {
				if errors.Is(err, reconfigure.ErrRunning) {
					return nil, &rpc.Error{Code: rpc.CodeActionFailed, Message: "the hostname is already being changed", Data: map[string]string{"reason": "busy"}}
				}

				return nil, err
			}

			return hostnameParams{Hostname: params.Hostname}, nil
		}},
		"system.set_timezone": {Handler: func(ctx context.Context, raw json.RawMessage) (any, error) {
			var params timezoneParams
			if err := rpc.DecodeParams(raw, &params); err != nil {
				return nil, err
			}

			if err := serverTime.SetTimezone(ctx, params.Timezone); err != nil {
				if errors.Is(err, clock.ErrUnknownTimezone) {
					return nil, rpc.Errorf(rpc.CodeInvalidParams, "invalid params: %q is not a timezone of this server", params.Timezone)
				}

				return nil, err
			}

			return timezoneParams{Timezone: serverTime.Timezone()}, nil
		}},
		"ssl.issue_hostname": {Handler: func(ctx context.Context, raw json.RawMessage) (any, error) {
			if err := rpc.DecodeParams(raw, &struct{}{}); err != nil {
				return nil, err
			}

			status, err := certificates.IssueHostnameCertificate(ctx)
			if errors.Is(err, webserver.ErrNoServerHostname) {
				return nil, rpc.Errorf(rpc.CodeActionFailed, "the server has no fully qualified hostname to get a certificate for")
			}

			if err != nil {
				hostname, _ := certificates.ServerHostname()

				return nil, webError(err, hostname)
			}

			return status, nil
		}},
	}
}

// portError explains why the panel cannot move to a port. A reason lets
// the panel tell a busy server apart.
func portError(err error, port int) error {
	busy := func(message string) error {
		return &rpc.Error{Code: rpc.CodeActionFailed, Message: message, Data: map[string]string{"reason": "busy"}}
	}

	switch {
	case errors.Is(err, panelport.ErrReservedPort):
		return rpc.Errorf(rpc.CodeInvalidParams, "invalid params: port %d is below 1024 or used by another service of this server", port)
	case errors.Is(err, panelport.ErrSamePort):
		return rpc.Errorf(rpc.CodeActionFailed, "the panel already uses port %d", port)
	case errors.Is(err, panelport.ErrPortInUse):
		return rpc.Errorf(rpc.CodeActionFailed, "port %d is already in use on this server", port)
	case errors.Is(err, panelport.ErrConfigRejected):
		return rpc.Errorf(rpc.CodeActionFailed, "Nginx refused the configuration for port %d", port)
	case errors.Is(err, panelport.ErrNoTrial):
		return rpc.Errorf(rpc.CodeActionFailed, "no port is being tried")
	case errors.Is(err, panelport.ErrTrialExpired):
		return rpc.Errorf(rpc.CodeActionFailed, "the port was closed again before it was confirmed; try it again")
	case errors.Is(err, panelport.ErrTrialRunning):
		return busy("another port is being tried; confirm or cancel it first")
	case errors.Is(err, reconfigure.ErrRunning):
		return busy("the server is already being configured; try again when it is done")
	}

	return err
}
