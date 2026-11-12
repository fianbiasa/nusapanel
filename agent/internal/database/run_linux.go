package database

import "syscall"

// sysProcAttr runs a program as as, with only its own group, and makes it
// die with the agent.
func sysProcAttr(as Identity) *syscall.SysProcAttr {
	return &syscall.SysProcAttr{
		Credential: &syscall.Credential{Uid: uint32(as.UID), Gid: uint32(as.GID), Groups: []uint32{}},
		Pdeathsig:  syscall.SIGKILL,
	}
}
