package actions

import (
	"context"
	"encoding/json"
	"errors"
	"net/netip"

	"rayxo/agent/internal/network"
	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/runner"
	"rayxo/agent/internal/validate"
)

// addressStateFile lists the IP addresses rayxod added.
const addressStateFile = "/etc/rayxo/ip-addresses"

// serverAddresses adds and removes the server's extra IP addresses.
// *network.Manager implements it.
type serverAddresses interface {
	List(ctx context.Context) ([]network.Address, error)
	Add(ctx context.Context, ip netip.Addr, prefix int) (bool, error)
	Remove(ctx context.Context, ip netip.Addr) error
}

// addressParams are the params of network.add_address and
// network.remove_address (which takes no prefix).
type addressParams struct {
	Address string `json:"address"`
	Prefix  int    `json:"prefix"`
}

// addressInfo is one address in the result of network.addresses.
type addressInfo struct {
	Address   string `json:"address"`
	Prefix    int    `json:"prefix"`
	Interface string `json:"interface"`
	// Added is whether rayxo added it, and so may remove it.
	Added bool `json:"added"`
	// Primary is whether it is its interface's main address of its
	// version, which no website may have for its own.
	Primary bool `json:"primary"`
}

type networkActions struct {
	addresses serverAddresses
}

// networkMethods returns the network.* methods. Changes take the one
// "network" lock; listing takes none.
func networkMethods(addresses serverAddresses) map[string]rpc.Method {
	n := networkActions{addresses: addresses}
	lock := func(json.RawMessage) (string, error) { return "network", nil }

	return map[string]rpc.Method{
		"network.addresses":      {Handler: n.list},
		"network.add_address":    {Handler: n.add, LockKey: lock},
		"network.remove_address": {Handler: n.remove, LockKey: lock},
	}
}

func defaultServerAddresses() *network.Manager {
	return network.New(network.Config{StateFile: addressStateFile, Run: runner.Run})
}

// RestoreAddresses adds the IP addresses rayxod added again when the agent
// starts, as they do not survive a reboot.
func RestoreAddresses(ctx context.Context) error {
	return defaultServerAddresses().Restore(ctx)
}

// list implements network.addresses: the global addresses of the server's
// interfaces.
func (n networkActions) list(ctx context.Context, raw json.RawMessage) (any, error) {
	if err := rpc.DecodeParams(raw, &struct{}{}); err != nil {
		return nil, err
	}

	addresses, err := n.addresses.List(ctx)
	if err != nil {
		return nil, err
	}

	result := struct {
		Addresses []addressInfo `json:"addresses"`
	}{Addresses: []addressInfo{}}

	for _, address := range addresses {
		result.Addresses = append(result.Addresses, addressInfo{
			Address:   address.Address.String(),
			Prefix:    address.Prefix,
			Interface: address.Interface,
			Added:     address.Added,
			Primary:   address.Primary,
		})
	}

	return result, nil
}

// add implements network.add_address: the address is added to the
// interface of the default route, unless the server has it already, which
// added false reports.
func (n networkActions) add(ctx context.Context, raw json.RawMessage) (any, error) {
	var params addressParams
	if err := rpc.DecodeParams(raw, &params); err != nil {
		return nil, err
	}

	ip, err := validate.IPAddress(params.Address)
	if err == nil {
		err = network.CheckPrefix(ip, params.Prefix)
	}

	if err != nil {
		return nil, rpc.Errorf(rpc.CodeInvalidParams, "invalid params: %v", err)
	}

	added, err := n.addresses.Add(ctx, ip, params.Prefix)
	if errors.Is(err, network.ErrPrimary) {
		return nil, rpc.Errorf(rpc.CodeActionFailed, "%s is the server's main address; websites without an address of their own use it", ip)
	}

	if err != nil {
		return nil, err
	}

	return struct {
		Added bool `json:"added"`
	}{Added: added}, nil
}

// remove implements network.remove_address, for addresses rayxo added only.
func (n networkActions) remove(ctx context.Context, raw json.RawMessage) (any, error) {
	var params struct {
		Address string `json:"address"`
	}
	if err := rpc.DecodeParams(raw, &params); err != nil {
		return nil, err
	}

	ip, err := validate.IPAddress(params.Address)
	if err != nil {
		return nil, rpc.Errorf(rpc.CodeInvalidParams, "invalid params: %v", err)
	}

	err = n.addresses.Remove(ctx, ip)
	if errors.Is(err, network.ErrNotAdded) {
		return nil, rpc.Errorf(rpc.CodeActionFailed, "%s was not added by RAYXO, so it stays", ip)
	}

	if err != nil {
		return nil, err
	}

	return struct{}{}, nil
}
