package cgroup

import (
	"bufio"
	"errors"
	"io/fs"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"

	"rayxo/agent/internal/validate"
)

// Account is a hosting account whose use is measured.
type Account struct {
	Name string
	UID  int
}

// Usage is what an account uses: the CPU time of each of its cgroups, and
// the memory its processes hold now.
type Usage struct {
	Username    string     `json:"username"`
	MemoryBytes uint64     `json:"memory_bytes"`
	CPU         []GroupCPU `json:"cpu"`
}

// GroupCPU is the CPU time a cgroup has used since it was created. ID is
// the cgroup's inode number: a cgroup removed and created again under the
// same name (a user slice after its last session ends, a restarted pool)
// gets a new one, and its count starts again from zero.
type GroupCPU struct {
	ID   uint64 `json:"id"`
	Usec uint64 `json:"usec"`
}

// phpPoolSlicePrefix starts the names of the slices systemd puts the
// instances of the rayxo-php<version>@.service templates in, while they
// run outside their account's slice (as before their unit named it).
const phpPoolSlicePrefix = `system-rayxo\x2dphp`

// Usage reads what each account uses from its cgroups: its slice (its
// PHP-FPM pools), its user slice (its SSH and SFTP sessions), and its PHP
// pools still running outside its slice. Cron jobs are not counted. A
// cgroup that does not exist is left out. Memory is the working set: what
// the processes hold, without the file cache the kernel can take back.
func (m *Manager) Usage(accounts []Account) ([]Usage, error) {
	outside, err := m.poolsOutsideSlices()
	if err != nil {
		return nil, err
	}

	usages := make([]Usage, 0, len(accounts))

	for _, account := range accounts {
		if err := validate.Username(account.Name); err != nil {
			return nil, err
		}

		groups := []string{
			filepath.Join(m.cfg.Root, "rayxo.slice", sliceName(account.Name)),
			filepath.Join(m.cfg.Root, "user.slice", "user-"+strconv.Itoa(account.UID)+".slice"),
		}
		groups = append(groups, outside[account.Name]...)

		usage := Usage{Username: account.Name, CPU: []GroupCPU{}}

		for _, group := range groups {
			read, found, err := readGroup(group)
			if err != nil {
				return nil, err
			}

			if found {
				usage.CPU = append(usage.CPU, read.cpu)
				usage.MemoryBytes += read.memory
			}
		}

		usages = append(usages, usage)
	}

	return usages, nil
}

// poolsOutsideSlices finds the cgroups of PHP pools that run in systemd's
// default slices, by account.
func (m *Manager) poolsOutsideSlices() (map[string][]string, error) {
	pools := map[string][]string{}
	systemSlice := filepath.Join(m.cfg.Root, "system.slice")

	slices, err := os.ReadDir(systemSlice)
	if errors.Is(err, fs.ErrNotExist) {
		return pools, nil
	}

	if err != nil {
		return nil, err
	}

	for _, slice := range slices {
		if !slice.IsDir() || !strings.HasPrefix(slice.Name(), phpPoolSlicePrefix) || !strings.HasSuffix(slice.Name(), ".slice") {
			continue
		}

		units, err := os.ReadDir(filepath.Join(systemSlice, slice.Name()))
		if err != nil {
			return nil, err
		}

		for _, unit := range units {
			name, isService := strings.CutSuffix(unit.Name(), ".service")
			_, user, isPool := strings.Cut(name, "@")

			if unit.IsDir() && isService && isPool && strings.HasPrefix(name, "rayxo-php") {
				pools[user] = append(pools[user], filepath.Join(systemSlice, slice.Name(), unit.Name()))
			}
		}
	}

	return pools, nil
}

// groupReading is what one cgroup has used.
type groupReading struct {
	cpu    GroupCPU
	memory uint64
}

// readGroup reads a cgroup's id, CPU time (cpu.stat usage_usec) and
// working set (memory.current less memory.stat inactive_file); found is
// false for one that does not exist.
func readGroup(dir string) (reading groupReading, found bool, err error) {
	info, err := os.Stat(dir)
	if errors.Is(err, fs.ErrNotExist) {
		return reading, false, nil
	}

	if err != nil {
		return reading, false, err
	}

	if stat, ok := info.Sys().(*syscall.Stat_t); ok {
		reading.cpu.ID = stat.Ino
	}

	stats, err := readStats(filepath.Join(dir, "cpu.stat"))
	if errors.Is(err, fs.ErrNotExist) {
		return reading, false, nil
	}

	if err != nil {
		return reading, false, err
	}

	reading.cpu.Usec = stats["usage_usec"]

	// The memory files are missing while the memory controller is off for
	// the cgroup.
	current, err := os.ReadFile(filepath.Join(dir, "memory.current"))
	if errors.Is(err, fs.ErrNotExist) {
		return reading, true, nil
	}

	if err != nil {
		return reading, false, err
	}

	memory, _ := strconv.ParseUint(strings.TrimSpace(string(current)), 10, 64)

	memoryStats, err := readStats(filepath.Join(dir, "memory.stat"))
	if err != nil && !errors.Is(err, fs.ErrNotExist) {
		return reading, false, err
	}

	if cache := memoryStats["inactive_file"]; cache < memory {
		reading.memory = memory - cache
	}

	return reading, true, nil
}

// readStats reads a flat-keyed cgroup file ("key value" per line).
func readStats(path string) (map[string]uint64, error) {
	file, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer file.Close()

	stats := map[string]uint64{}
	scanner := bufio.NewScanner(file)

	for scanner.Scan() {
		key, value, found := strings.Cut(scanner.Text(), " ")
		if !found {
			continue
		}

		if number, err := strconv.ParseUint(strings.TrimSpace(value), 10, 64); err == nil {
			stats[key] = number
		}
	}

	return stats, scanner.Err()
}
