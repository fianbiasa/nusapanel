//go:build darwin

package sysinfo

import (
	"fmt"
	"os"
	"runtime"
	"time"

	"golang.org/x/sys/unix"
)

// collect on macOS exists only so rayxod can run next to the panel in Herd
// during development. Used memory approximates Activity Monitor's "Memory
// Used": total minus free, speculative, file-cache and purgeable pages.
func collect() (*Info, error) {
	hostname, err := os.Hostname()
	if err != nil {
		return nil, fmt.Errorf("read hostname: %w", err)
	}

	productVersion, _ := unix.Sysctl("kern.osproductversion")
	kernel, _ := unix.Sysctl("kern.osrelease")

	cpuModel, err := unix.Sysctl("machdep.cpu.brand_string")
	if err != nil || cpuModel == "" {
		cpuModel = runtime.GOARCH
	}

	rawLoad, err := unix.SysctlRaw("vm.loadavg")
	if err != nil {
		return nil, fmt.Errorf("read load average: %w", err)
	}

	load, err := decodeDarwinLoadAvg(rawLoad)
	if err != nil {
		return nil, err
	}

	totalBytes, err := unix.SysctlUint64("hw.memsize")
	if err != nil {
		return nil, fmt.Errorf("read memory size: %w", err)
	}

	pageSize := uint64(os.Getpagesize())
	var reclaimablePages uint64

	for _, counter := range []string{
		"vm.page_free_count",
		"vm.page_speculative_count",
		"vm.page_pageable_external_count",
		"vm.page_purgeable_count",
	} {
		if pages, err := unix.SysctlUint32(counter); err == nil {
			reclaimablePages += uint64(pages)
		}
	}

	freeBytes := min(reclaimablePages*pageSize, totalBytes)

	bootTime, err := unix.SysctlTimeval("kern.boottime")
	if err != nil {
		return nil, fmt.Errorf("read boot time: %w", err)
	}

	uptime := time.Since(time.Unix(bootTime.Sec, int64(bootTime.Usec)*int64(time.Microsecond)))

	disk, err := diskUsage("/System/Volumes/Data")
	if err != nil {
		disk, err = diskUsage("/")
		if err != nil {
			return nil, err
		}
	}

	return &Info{
		Hostname:      hostname,
		OS:            OS{Name: "macOS", Version: productVersion, Kernel: kernel},
		CPU:           CPU{Model: cpuModel, Cores: runtime.NumCPU()},
		Load:          load,
		Memory:        Memory{TotalMB: totalBytes >> 20, UsedMB: (totalBytes - freeBytes) >> 20},
		Disk:          disk,
		UptimeSeconds: uint64(uptime.Seconds()),
	}, nil
}
