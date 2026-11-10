//go:build !linux

package files

import "syscall"

// sysProcAttr makes the helper run as the account, with only the account's
// own group. Accounts only exist on Linux; this lets the agent build and be
// tested on macOS.
func sysProcAttr(account Account) *syscall.SysProcAttr {
	return &syscall.SysProcAttr{
		Credential: &syscall.Credential{
			Uid:    uint32(account.UID),
			Gid:    uint32(account.GID),
			Groups: []uint32{},
		},
	}
}
