package files

import "syscall"

// sysProcAttr makes the helper run as the account, with only the account's
// own group, and die with the agent.
func sysProcAttr(account Account) *syscall.SysProcAttr {
	return &syscall.SysProcAttr{
		Credential: &syscall.Credential{
			Uid:    uint32(account.UID),
			Gid:    uint32(account.GID),
			Groups: []uint32{},
		},
		Pdeathsig: syscall.SIGKILL,
	}
}
