package sysinfo

import (
	"context"
	"slices"
	"strings"
	"time"
)

// Software is an installed server program and its version.
type Software struct {
	Name    string `json:"name"`
	Version string `json:"version"`
}

const dpkgQueryProgram = "/usr/bin/dpkg-query"

// softwarePackages are the Debian packages of the programs RAYXO runs, in the
// order they are shown, with their names. PHP versions follow them.
var softwarePackages = []struct{ pkg, name string }{
	{"nginx", "Nginx"},
	{"apache2", "Apache"},
	{"mariadb-server", "MariaDB"},
	{"postfix", "Postfix"},
	{"dovecot-core", "Dovecot"},
	{"rspamd", "Rspamd"},
	{"redis-server", "Redis"},
	{"pdns-server", "PowerDNS"},
	{"pure-ftpd-mysql", "Pure-FTPd"},
}

// collectSoftware returns the versions of the installed programs RAYXO
// runs, as dpkg knows them; nothing when dpkg cannot be asked.
func collectSoftware(run func(ctx context.Context, program string, args ...string) ([]byte, error)) []Software {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	args := []string{"-W", "-f", "${db:Status-Abbrev}\t${Package}\t${Version}\n"}
	for _, entry := range softwarePackages {
		args = append(args, entry.pkg)
	}

	// dpkg-query exits 1 when one of the packages is not installed, and
	// still lists the others.
	output, _ := run(ctx, dpkgQueryProgram, append(args, "php*-fpm")...)

	return parseSoftware(string(output))
}

// parseSoftware turns dpkg-query's "<status>\t<package>\t<version>" lines
// into the installed programs: the known ones in their order, then PHP
// versions from the oldest. Other lines, such as dpkg-query's complaints
// about packages it does not know, are left out.
func parseSoftware(output string) []Software {
	installed := map[string]string{}
	var php []string

	for line := range strings.Lines(output) {
		fields := strings.Split(strings.TrimRight(line, "\n"), "\t")
		if len(fields) != 3 || !strings.HasPrefix(fields[0], "ii") || fields[2] == "" {
			continue
		}

		pkg, version := fields[1], upstreamVersion(fields[2])
		installed[pkg] = version

		if strings.HasPrefix(pkg, "php") && strings.HasSuffix(pkg, "-fpm") {
			php = append(php, pkg)
		}
	}

	software := []Software{}

	for _, entry := range softwarePackages {
		if version, ok := installed[entry.pkg]; ok {
			software = append(software, Software{Name: entry.name, Version: version})
		}
	}

	slices.SortFunc(php, func(a, b string) int { return compareVersions(phpVersion(a), phpVersion(b)) })

	for _, pkg := range php {
		software = append(software, Software{Name: "PHP " + phpVersion(pkg), Version: installed[pkg]})
	}

	return software
}

// upstreamVersion drops the epoch and the distribution's revision from a
// Debian version: 1:10.11.13-0ubuntu0.24.04.1 is 10.11.13.
func upstreamVersion(version string) string {
	if _, rest, ok := strings.Cut(version, ":"); ok {
		version = rest
	}

	if index := strings.IndexAny(version, "-+~"); index > 0 {
		version = version[:index]
	}

	return version
}

// phpVersion is the PHP version of a php<version>-fpm package.
func phpVersion(pkg string) string {
	return strings.TrimSuffix(strings.TrimPrefix(pkg, "php"), "-fpm")
}

// compareVersions orders dotted numbers such as 7.4 and 8.10.
func compareVersions(a, b string) int {
	partsA, partsB := strings.Split(a, "."), strings.Split(b, ".")

	for i := 0; i < len(partsA) && i < len(partsB); i++ {
		if diff := len(partsA[i]) - len(partsB[i]); diff != 0 {
			return diff
		}

		if c := strings.Compare(partsA[i], partsB[i]); c != 0 {
			return c
		}
	}

	return len(partsA) - len(partsB)
}
