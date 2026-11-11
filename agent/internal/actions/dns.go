package actions

import (
	"context"
	"encoding/json"

	"rayxo/agent/internal/dns"
	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/validate"
)

// dnsServer is what the dns.* actions need from the DNS server.
// dns.Manager implements it.
type dnsServer interface {
	Sync(ctx context.Context, zone dns.Zone) error
	Delete(ctx context.Context, name string) error
}

// syncZoneParams are the params of dns.sync_zone: a zone of the account
// with its complete content.
type syncZoneParams struct {
	Username    string       `json:"username"`
	Zone        string       `json:"zone"`
	Nameservers []string     `json:"nameservers"`
	Records     []dns.Record `json:"records"`
}

func (p syncZoneParams) account() string { return p.Username }

func (p syncZoneParams) validate() error {
	return firstError(validate.Username(p.Username), dns.Validate(p.zone()))
}

func (p syncZoneParams) zone() dns.Zone {
	return dns.Zone{Name: p.Zone, Nameservers: p.Nameservers, Records: p.Records}
}

// deleteZoneParams are the params of dns.delete_zone.
type deleteZoneParams struct {
	Username string `json:"username"`
	Zone     string `json:"zone"`
}

func (p deleteZoneParams) account() string { return p.Username }

func (p deleteZoneParams) validate() error {
	return firstError(validate.Username(p.Username), validate.Domain(p.Zone))
}

// dnsActions implements the dns.* methods.
type dnsActions struct {
	server dnsServer
}

// dnsMethods returns the dns.* methods by name. They lock the account like
// the web.* methods. Zones live in PowerDNS only, so they need no Linux
// account: the zones of an account that is already gone can be deleted.
func dnsMethods(server dnsServer) map[string]rpc.Method {
	actions := dnsActions{server: server}

	return map[string]rpc.Method{
		"dns.sync_zone":   {Handler: actions.syncZone, LockKey: webLockKey[syncZoneParams]},
		"dns.delete_zone": {Handler: actions.deleteZone, LockKey: webLockKey[deleteZoneParams]},
	}
}

// syncZone implements dns.sync_zone: PowerDNS ends up holding exactly the
// zone given, created when it is missing.
func (d dnsActions) syncZone(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[syncZoneParams](raw)
	if err != nil {
		return nil, err
	}

	if err := d.server.Sync(ctx, params.zone()); err != nil {
		return nil, rpc.Errorf(rpc.CodeActionFailed, "%v", err)
	}

	return struct{}{}, nil
}

// deleteZone implements dns.delete_zone. A zone that is not there succeeds.
func (d dnsActions) deleteZone(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[deleteZoneParams](raw)
	if err != nil {
		return nil, err
	}

	if err := d.server.Delete(ctx, params.Zone); err != nil {
		return nil, rpc.Errorf(rpc.CodeActionFailed, "%v", err)
	}

	return struct{}{}, nil
}
