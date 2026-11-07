//go:build darwin

package sysinfo

import (
	"fmt"
	"os"
	"runtime"
	"time"

	"golang.org/x/sys/unix"
)

// collect on macOS exists only so nusad can run next to the panel in Herd
// during development. Used memory is approximate: macOS counts file cache as
// in use, so the figure is higher than Activity Monitor's "Memory Used".
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
	freePages, _ := unix.SysctlUint32("vm.page_free_count")
	speculativePages, _ := unix.SysctlUint32("vm.page_speculative_count")
	freeBytes := min((uint64(freePages)+uint64(speculativePages))*pageSize, totalBytes)

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
