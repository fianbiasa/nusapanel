//go:build linux

package sysinfo

import (
	"errors"
	"fmt"
	"io/fs"
	"os"
	"runtime"

	"golang.org/x/sys/unix"

	"rayxo/agent/internal/runner"
)

func collect() (*Info, error) {
	hostname, err := os.Hostname()
	if err != nil {
		return nil, fmt.Errorf("read hostname: %w", err)
	}

	osName, osVersion := parseOSRelease(readOptional("/etc/os-release", "/usr/lib/os-release"))

	var uts unix.Utsname
	if err := unix.Uname(&uts); err != nil {
		return nil, fmt.Errorf("read kernel version: %w", err)
	}

	cpuModel := parseCPUModel(readOptional("/proc/cpuinfo"))
	if cpuModel == "" {
		cpuModel = runtime.GOARCH
	}

	loadContent, err := os.ReadFile("/proc/loadavg")
	if err != nil {
		return nil, fmt.Errorf("read load average: %w", err)
	}

	load, err := parseLoadAvg(string(loadContent))
	if err != nil {
		return nil, err
	}

	memContent, err := os.ReadFile("/proc/meminfo")
	if err != nil {
		return nil, fmt.Errorf("read memory usage: %w", err)
	}

	totalKB, availableKB, err := parseMemInfo(string(memContent))
	if err != nil {
		return nil, err
	}

	uptimeContent, err := os.ReadFile("/proc/uptime")
	if err != nil {
		return nil, fmt.Errorf("read uptime: %w", err)
	}

	uptime, err := parseUptime(string(uptimeContent))
	if err != nil {
		return nil, err
	}

	disk, err := diskUsage("/")
	if err != nil {
		return nil, err
	}

	return &Info{
		Hostname: hostname,
		OS: OS{
			Name:    osName,
			Version: osVersion,
			Kernel:  unix.ByteSliceToString(uts.Release[:]),
		},
		CPU:           CPU{Model: cpuModel, Cores: runtime.NumCPU()},
		Load:          load,
		Memory:        Memory{TotalMB: totalKB / 1024, UsedMB: (totalKB - availableKB) / 1024},
		Disk:          disk,
		UptimeSeconds: uptime,
		Software:      collectSoftware(runner.Run),
	}, nil
}

// readOptional returns the contents of the first path that exists, or "".
func readOptional(paths ...string) string {
	for _, path := range paths {
		content, err := os.ReadFile(path)
		if err == nil {
			return string(content)
		}

		if !errors.Is(err, fs.ErrNotExist) {
			return ""
		}
	}

	return ""
}
