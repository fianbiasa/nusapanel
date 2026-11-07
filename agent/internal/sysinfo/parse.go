package sysinfo

import (
	"bufio"
	"encoding/binary"
	"errors"
	"fmt"
	"strconv"
	"strings"
)

// parseOSRelease reads NAME and VERSION_ID (falling back to VERSION) from
// the contents of /etc/os-release.
func parseOSRelease(content string) (name, version string) {
	values := map[string]string{}

	scanner := bufio.NewScanner(strings.NewReader(content))
	for scanner.Scan() {
		key, value, found := strings.Cut(strings.TrimSpace(scanner.Text()), "=")
		if !found || strings.HasPrefix(key, "#") {
			continue
		}

		values[key] = strings.Trim(value, `"'`)
	}

	version = values["VERSION_ID"]
	if version == "" {
		version = values["VERSION"]
	}

	return values["NAME"], version
}

// parseLoadAvg reads the 1, 5 and 15 minute load averages from /proc/loadavg.
func parseLoadAvg(content string) ([3]float64, error) {
	var load [3]float64

	fields := strings.Fields(content)
	if len(fields) < 3 {
		return load, errors.New("unexpected /proc/loadavg format")
	}

	for i := range load {
		value, err := strconv.ParseFloat(fields[i], 64)
		if err != nil {
			return load, fmt.Errorf("parse load average: %w", err)
		}

		load[i] = value
	}

	return load, nil
}

// parseMemInfo reads total and available memory, in kibibytes, from
// /proc/meminfo. Kernels without MemAvailable fall back to free + cache.
func parseMemInfo(content string) (totalKB, availableKB uint64, err error) {
	values := map[string]uint64{}

	scanner := bufio.NewScanner(strings.NewReader(content))
	for scanner.Scan() {
		key, rest, found := strings.Cut(scanner.Text(), ":")
		if !found {
			continue
		}

		fields := strings.Fields(rest)
		if len(fields) == 0 {
			continue
		}

		value, parseErr := strconv.ParseUint(fields[0], 10, 64)
		if parseErr != nil {
			continue
		}

		values[key] = value
	}

	totalKB, ok := values["MemTotal"]
	if !ok || totalKB == 0 {
		return 0, 0, errors.New("MemTotal missing from /proc/meminfo")
	}

	availableKB, ok = values["MemAvailable"]
	if !ok {
		availableKB = values["MemFree"] + values["Buffers"] + values["Cached"]
	}

	return totalKB, min(availableKB, totalKB), nil
}

// parseCPUModel returns the processor name from /proc/cpuinfo, or "" when
// the kernel does not report one (common on ARM).
func parseCPUModel(content string) string {
	scanner := bufio.NewScanner(strings.NewReader(content))
	for scanner.Scan() {
		key, value, found := strings.Cut(scanner.Text(), ":")
		if !found {
			continue
		}

		switch strings.TrimSpace(key) {
		case "model name", "Model", "Hardware":
			if model := strings.TrimSpace(value); model != "" {
				return model
			}
		}
	}

	return ""
}

// parseUptime reads whole seconds since boot from /proc/uptime.
func parseUptime(content string) (uint64, error) {
	fields := strings.Fields(content)
	if len(fields) == 0 {
		return 0, errors.New("unexpected /proc/uptime format")
	}

	seconds, err := strconv.ParseFloat(fields[0], 64)
	if err != nil || seconds < 0 {
		return 0, fmt.Errorf("parse uptime %q", fields[0])
	}

	return uint64(seconds), nil
}

// decodeDarwinLoadAvg decodes the macOS "vm.loadavg" sysctl, a C struct of
// three 32-bit fixed-point values followed by a 64-bit scale factor.
func decodeDarwinLoadAvg(raw []byte) ([3]float64, error) {
	var load [3]float64

	if len(raw) < 24 {
		return load, fmt.Errorf("vm.loadavg is %d bytes, expected 24", len(raw))
	}

	scale := binary.LittleEndian.Uint64(raw[16:24])
	if scale == 0 {
		return load, errors.New("vm.loadavg has a zero scale")
	}

	for i := range load {
		load[i] = float64(binary.LittleEndian.Uint32(raw[i*4:i*4+4])) / float64(scale)
	}

	return load, nil
}
