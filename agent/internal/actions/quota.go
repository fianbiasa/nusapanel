package actions

import (
	"cmp"
	"context"
	"encoding/json"
	"errors"
	"slices"

	"nusapanel/agent/internal/quota"
	"nusapanel/agent/internal/rpc"
	"nusapanel/agent/internal/validate"
)

// maxDiskMB is the largest disk_mb whose limit fits the quota format.
const maxDiskMB = quota.MaxBlocksKB / 1024

// setQuotaParams are the params of account.set_quota. Both limits are
// required, so a caller that forgets one never removes a limit by accident.
type setQuotaParams struct {
	Username string `json:"username"`
	// DiskMB limits disk space in MiB; 0 is unlimited.
	DiskMB *int64 `json:"disk_mb"`
	// Inodes limits the number of files and directories; 0 is unlimited.
	Inodes *int64 `json:"inodes"`
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

	return nil
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
// limits alike (no grace period). When that filesystem has no user quotas
// on, nothing changes and applied is false.
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

	return setQuotaResult{Applied: applied}, nil
}

// quotaReport implements quota.report: the disk usage and limits of every
// nusapanel account, sorted by username. Accounts without a quota entry use
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
