// Package sysinfo reads basic information about the host for system.info.
package sysinfo

import "math"

// Info is the result of system.info. The panel's FakeAgentClient returns the
// same shape, so keep both in sync.
type Info struct {
	Hostname      string     `json:"hostname"`
	OS            OS         `json:"os"`
	CPU           CPU        `json:"cpu"`
	Load          [3]float64 `json:"load"`
	Memory        Memory     `json:"memory"`
	Disk          Disk       `json:"disk"`
	UptimeSeconds uint64     `json:"uptime_seconds"`
	AgentVersion  string     `json:"agent_version"`
	// Software is the version of each server program RAYXO runs; empty
	// where dpkg cannot be asked (development on macOS).
	Software []Software `json:"software"`
}

// OS identifies the operating system.
type OS struct {
	Name    string `json:"name"`
	Version string `json:"version"`
	Kernel  string `json:"kernel"`
}

// CPU describes the processor.
type CPU struct {
	Model string `json:"model"`
	Cores int    `json:"cores"`
}

// Memory is RAM usage in mebibytes.
type Memory struct {
	TotalMB uint64 `json:"total_mb"`
	UsedMB  uint64 `json:"used_mb"`
}

// Disk is usage of one filesystem in gibibytes.
type Disk struct {
	Mount   string  `json:"mount"`
	TotalGB float64 `json:"total_gb"`
	UsedGB  float64 `json:"used_gb"`
}

// Collect gathers information about the host the agent runs on.
func Collect() (*Info, error) {
	info, err := collect()
	if err != nil {
		return nil, err
	}

	if info.Software == nil {
		info.Software = []Software{}
	}

	return info, nil
}

func roundGiB(bytes uint64) float64 {
	return math.Round(float64(bytes)/(1<<30)*10) / 10
}
