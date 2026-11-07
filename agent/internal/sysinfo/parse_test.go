package sysinfo

import (
	"encoding/binary"
	"runtime"
	"testing"
)

func TestParseOSRelease(t *testing.T) {
	name, version := parseOSRelease(`PRETTY_NAME="Ubuntu 24.04.1 LTS"
NAME="Ubuntu"
VERSION_ID="24.04"
VERSION="24.04.1 LTS (Noble Numbat)"
ID=ubuntu
`)

	if name != "Ubuntu" || version != "24.04" {
		t.Fatalf("got %q %q", name, version)
	}

	_, fallback := parseOSRelease("NAME=Debian\nVERSION=\"13 (trixie)\"\n")
	if fallback != "13 (trixie)" {
		t.Fatalf("expected VERSION fallback, got %q", fallback)
	}
}

func TestParseLoadAvg(t *testing.T) {
	load, err := parseLoadAvg("0.42 0.37 0.31 2/731 12345\n")
	if err != nil {
		t.Fatal(err)
	}

	if load != [3]float64{0.42, 0.37, 0.31} {
		t.Fatalf("got %v", load)
	}

	if _, err := parseLoadAvg("garbage"); err == nil {
		t.Fatal("expected an error for malformed input")
	}
}

func TestParseMemInfo(t *testing.T) {
	total, available, err := parseMemInfo(`MemTotal:        8130724 kB
MemFree:          912340 kB
MemAvailable:    5021132 kB
Buffers:          201320 kB
Cached:          3301488 kB
`)
	if err != nil {
		t.Fatal(err)
	}

	if total != 8130724 || available != 5021132 {
		t.Fatalf("got total %d available %d", total, available)
	}
}

func TestParseMemInfoWithoutMemAvailable(t *testing.T) {
	_, available, err := parseMemInfo("MemTotal: 1000 kB\nMemFree: 100 kB\nBuffers: 50 kB\nCached: 250 kB\n")
	if err != nil {
		t.Fatal(err)
	}

	if available != 400 {
		t.Fatalf("expected free + buffers + cached = 400, got %d", available)
	}

	if _, _, err := parseMemInfo("MemFree: 1 kB\n"); err == nil {
		t.Fatal("expected an error when MemTotal is missing")
	}
}

func TestParseCPUModel(t *testing.T) {
	x86 := "processor\t: 0\nvendor_id\t: GenuineIntel\nmodel name\t: Intel(R) Xeon(R) CPU @ 2.20GHz\n"
	if model := parseCPUModel(x86); model != "Intel(R) Xeon(R) CPU @ 2.20GHz" {
		t.Fatalf("got %q", model)
	}

	arm := "processor\t: 0\nBogoMIPS\t: 50.00\nCPU implementer\t: 0x41\n"
	if model := parseCPUModel(arm); model != "" {
		t.Fatalf("expected no model for ARM without a name, got %q", model)
	}
}

func TestParseUptime(t *testing.T) {
	seconds, err := parseUptime("1036800.57 4021234.10\n")
	if err != nil {
		t.Fatal(err)
	}

	if seconds != 1036800 {
		t.Fatalf("got %d", seconds)
	}
}

func TestDecodeDarwinLoadAvg(t *testing.T) {
	raw := make([]byte, 24)
	binary.LittleEndian.PutUint32(raw[0:4], 3*2048)
	binary.LittleEndian.PutUint32(raw[4:8], 2048)
	binary.LittleEndian.PutUint32(raw[8:12], 1024)
	binary.LittleEndian.PutUint64(raw[16:24], 2048)

	load, err := decodeDarwinLoadAvg(raw)
	if err != nil {
		t.Fatal(err)
	}

	if load != [3]float64{3, 1, 0.5} {
		t.Fatalf("got %v", load)
	}

	if _, err := decodeDarwinLoadAvg(raw[:8]); err == nil {
		t.Fatal("expected an error for a short buffer")
	}
}

func TestCollectReadsThisHost(t *testing.T) {
	if runtime.GOOS != "linux" && runtime.GOOS != "darwin" {
		t.Skip("system.info only supports Linux and macOS")
	}

	info, err := Collect()
	if err != nil {
		t.Fatalf("collect failed: %v", err)
	}

	if info.Hostname == "" || info.CPU.Cores < 1 || info.Memory.TotalMB == 0 || info.Disk.TotalGB <= 0 {
		t.Fatalf("incomplete system information: %+v", info)
	}

	if info.Memory.UsedMB > info.Memory.TotalMB || info.Disk.UsedGB > info.Disk.TotalGB {
		t.Fatalf("usage exceeds capacity: %+v", info)
	}
}
