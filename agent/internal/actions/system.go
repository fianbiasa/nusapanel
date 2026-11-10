package actions

import (
	"context"
	"encoding/json"
	"errors"

	"rayxo/agent/internal/clock"
	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/webserver"
)

// hostnameCertificates is the server's own certificate, the one the panel,
// phpMyAdmin and FTP serve. webserver.Manager implements it.
type hostnameCertificates interface {
	ServerHostname() (string, error)
	HostnameCertificateStatus() (webserver.HostnameCertificate, error)
	IssueHostnameCertificate(ctx context.Context) (webserver.HostnameCertificate, error)
}

// serverClock is the server's timezone. clock.Manager implements it.
type serverClock interface {
	Timezone() string
	SetTimezone(ctx context.Context, timezone string) error
}

// serverSettings is the result of system.settings. Certificate is nil when
// the panel's certificate cannot be read.
type serverSettings struct {
	Hostname    string                         `json:"hostname"`
	Timezone    string                         `json:"timezone"`
	Certificate *webserver.HostnameCertificate `json:"certificate"`
}

type timezoneParams struct {
	Timezone string `json:"timezone"`
}

// systemMethods returns the methods behind the panel's server settings.
func systemMethods(certificates hostnameCertificates, serverTime serverClock) map[string]rpc.Method {
	return map[string]rpc.Method{
		"system.settings": {Handler: func(_ context.Context, raw json.RawMessage) (any, error) {
			if err := rpc.DecodeParams(raw, &struct{}{}); err != nil {
				return nil, err
			}

			settings := serverSettings{Timezone: serverTime.Timezone()}
			settings.Hostname, _ = certificates.ServerHostname()

			if status, err := certificates.HostnameCertificateStatus(); err == nil {
				settings.Certificate = &status
			}

			return settings, nil
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
