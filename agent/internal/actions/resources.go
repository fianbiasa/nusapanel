package actions

import (
	"context"
	"encoding/json"

	"rayxo/agent/internal/cgroup"
	"rayxo/agent/internal/rpc"
)

// resourceReader measures what accounts use. *cgroup.Manager implements it.
type resourceReader interface {
	Usage(accounts []cgroup.Account) ([]cgroup.Usage, error)
}

// resourceMethods returns usage.resources, which only reads and locks
// nothing.
func resourceMethods(system accountSystem, reader resourceReader) map[string]rpc.Method {
	accounts := accountActions{system: system}

	return map[string]rpc.Method{
		"usage.resources": {Handler: func(ctx context.Context, raw json.RawMessage) (any, error) {
			return accounts.resources(ctx, raw, reader)
		}},
	}
}

// resources implements usage.resources: the CPU time of each cgroup of
// every rayxo account (microseconds since the cgroup was created, with
// the cgroup's id) and the memory the account's processes hold, sorted by
// username as the system lists them.
func (a accountActions) resources(ctx context.Context, raw json.RawMessage, reader resourceReader) (any, error) {
	if err := rpc.DecodeParams(raw, &struct{}{}); err != nil {
		return nil, err
	}

	users, err := a.system.ListUsers(ctx)
	if err != nil {
		return nil, systemError(err)
	}

	var accounts []cgroup.Account

	for _, user := range users {
		if checkManaged(user) == nil {
			accounts = append(accounts, cgroup.Account{Name: user.Name, UID: user.UID})
		}
	}

	usages, err := reader.Usage(accounts)
	if err != nil {
		return nil, err
	}

	return struct {
		Accounts []cgroup.Usage `json:"accounts"`
	}{Accounts: usages}, nil
}
