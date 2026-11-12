//go:build !linux

package database

import "syscall"

// sysProcAttr runs a program as as; outside Linux (development only) it
// cannot also die with the agent.
func sysProcAttr(as Identity) *syscall.SysProcAttr {
	return &syscall.SysProcAttr{
		Credential: &syscall.Credential{Uid: uint32(as.UID), Gid: uint32(as.GID), Groups: []uint32{}},
	}
}
