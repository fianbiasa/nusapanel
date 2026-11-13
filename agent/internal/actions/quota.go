package actions

import (
	"cmp"
	"context"
	"encoding/json"
	"errors"
	"slices"

	"rayxo/agent/internal/cgroup"
	"rayxo/agent/internal/quota"
	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/validate"
)

// maxDiskMB is the largest disk_mb whose limit fits the quota format.
const maxDiskMB = quota.MaxBlocksKB / 1024

// setQuotaParams are the params of account.set_quota. Both disk limits are
// required, so a caller that forgets one never removes a limit by accident.
// The resource limits are optional, but come all four or not at all;
// without them the account's resource limits stay as they are.
type setQuotaParams struct {
	Username string `json:"username"`
	// DiskMB limits disk space in MiB; 0 is unlimited.
	DiskMB *int64 `json:"disk_mb"`
	// Inodes limits the number of files and directories; 0 is unlimited.
	Inodes *int64 `json:"inodes"`
	// CPUPercent limits CPU time in percent of one CPU; 0 is unlimited.
	CPUPercent *int `json:"cpu_percent"`
	// MemoryMB limits memory in MiB; 0 is unlimited.
	MemoryMB *int `json:"memory_mb"`
	// Tasks limits the number of processes and threads; 0 is unlimited.
	Tasks *int `json:"tasks"`
	// IOWeight is the share of disk IO, 1 to 10000; 0 is the default.
	IOWeight *int `json:"io_weight"`
}

// resourceLimits returns the resource limits in the params, and whether
// there are any.
func (p setQuotaParams) resourceLimits() (cgroup.Limits, bool) {
	if p.CPUPercent == nil || p.MemoryMB == nil || p.Tasks == nil || p.IOWeight == nil {
		return cgroup.Limits{}, false
	}

	return cgroup.Limits{CPUPercent: *p.CPUPercent, MemoryMB: *p.MemoryMB, Tasks: *p.Tasks, IOWeight: *p.IOWeight}, true
}

func (p setQuotaParams) validate() error {
	if err := validate.Username(p.Username); err != nil {
		return err
	}

	switch {
	case p.DiskMB == nil:
		return errors.New("disk_mb is required")
	case *p.DiskMB < 0 || *p.DiskMB > maxDiskMB:
		return errors.New("disk_mb must be 0 (unlimited) or a positive number of MiB")
	case p.Inodes == nil:
		return errors.New("inodes is required")
	case *p.Inodes < 0 || *p.Inodes > quota.MaxInodes:
		return errors.New("inodes must be 0 (unlimited) or a positive number")
	}

	limits, given := p.resourceLimits()
	if !given {
		if p.CPUPercent != nil || p.MemoryMB != nil || p.Tasks != nil || p.IOWeight != nil {
			return errors.New("cpu_percent, memory_mb, tasks and io_weight come together")
		}

		return nil
	}

	return limits.Check()
}

// setQuotaResult is the result of account.set_quota.
type setQuotaResult struct {
	// Applied is false when the server has no user quotas on the
	// filesystem of the homes, so the limits could not be enforced.
	Applied bool `json:"applied"`
}

// quotaReport is the result of quota.report.
type quotaReport struct {
	Enabled    bool           `json:"enabled"`
	Filesystem string         `json:"filesystem"`
	Accounts   []quotaAccount `json:"accounts"`
}

// quotaAccount is the disk usage and the limits of one account. Space is
// in KiB; limits of 0 mean none.
type quotaAccount struct {
	Username    string `json:"username"`
	UID         int    `json:"uid"`
	DiskKB      int64  `json:"disk_kb"`
	Inodes      int64  `json:"inodes"`
	DiskLimitKB int64  `json:"disk_limit_kb"`
	InodeLimit  int64  `json:"inode_limit"`
}

// setQuotaLockKey locks the account named in the params of
// account.set_quota.
func setQuotaLockKey(raw json.RawMessage) (string, error) {
	params, err := decodeSetQuotaParams(raw)
	if err != nil {
		return "", err
	}

	return "account:" + params.Username, nil
}

// decodeSetQuotaParams strictly decodes and validates the params of
// account.set_quota.
func decodeSetQuotaParams(raw json.RawMessage) (setQuotaParams, error) {
	var params setQuotaParams
	if err := rpc.DecodeParams(raw, &params); err != nil {
		return params, err
	}

	if err := params.validate(); err != nil {
		return params, rpc.Errorf(rpc.CodeInvalidParams, "invalid params: %v", err)
	}

	return params, nil
}

// setQuota implements account.set_quota: it limits the disk space and the
// inodes of the account on the filesystem of the homes, with soft and hard
// limits alike (no grace period), and, when the params have them, its CPU,
// memory, processes and disk IO. When that filesystem has no user quotas
// on, the disk limits change nothing and applied is false.
func (a accountActions) setQuota(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeSetQuotaParams(raw)
	if err != nil {
		return nil, err
	}

	user, err := a.requireAccount(ctx, params.Username)
	if err != nil {
		return nil, err
	}

	applied, err := a.quotas.SetUserLimits(ctx, user.Name, quota.Limits{
		BlocksKB: *params.DiskMB * 1024,
		Inodes:   *params.Inodes,
	})
	if err != nil {
		return nil, err
	}

	if limits, given := params.resourceLimits(); given {
		if err := a.limits.Apply(ctx, user.Name, user.UID, limits); err != nil {
			return nil, err
		}
	}

	return setQuotaResult{Applied: applied}, nil
}

// quotaReport implements quota.report: the disk usage and limits of every
// rayxo account, sorted by username. Accounts without a quota entry use
// nothing and have no limits. When user quotas are off, enabled is false
// and accounts is empty.
func (a accountActions) quotaReport(ctx context.Context, raw json.RawMessage) (any, error) {
	if err := rpc.DecodeParams(raw, &struct{}{}); err != nil {
		return nil, err
	}

	report, err := a.quotas.Report(ctx)
	if err != nil {
		return nil, err
	}

	result := quotaReport{Enabled: report.Enabled, Filesystem: report.MountPoint, Accounts: []quotaAccount{}}
	if !report.Enabled {
		return result, nil
	}

	users, err := a.system.ListUsers(ctx)
	if err != nil {
		return nil, systemError(err)
	}

	usage := make(map[int]quota.Usage, len(report.Users))
	for _, entry := range report.Users {
		usage[entry.UID] = entry
	}

	for _, user := range users {
		if checkManaged(user) != nil {
			continue
		}

		entry := usage[user.UID]
		result.Accounts = append(result.Accounts, quotaAccount{
			Username:    user.Name,
			UID:         user.UID,
			DiskKB:      entry.BlocksKB,
			Inodes:      entry.Inodes,
			DiskLimitKB: entry.BlockLimitKB,
			InodeLimit:  entry.InodeLimit,
		})
	}

	slices.SortFunc(result.Accounts, func(x, y quotaAccount) int { return cmp.Compare(x.Username, y.Username) })

	return result, nil
}
