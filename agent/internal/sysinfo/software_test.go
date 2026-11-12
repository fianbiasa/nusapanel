package sysinfo

import (
	"context"
	"errors"
	"reflect"
	"strings"
	"testing"
)

const dpkgOutput = "ii \tnginx\t1.24.0-2ubuntu7.5\n" +
	"ii \tapache2\t2.4.58-1ubuntu8.8\n" +
	"ii \tmariadb-server\t1:10.11.13-0ubuntu0.24.04.1\n" +
	"ii \tpostfix\t3.8.6-1build2\n" +
	"ii \tdovecot-core\t1:2.3.21+dfsg1-2ubuntu6\n" +
	"ii \tredis-server\t5:7.0.15-1build2\n" +
	"un \trspamd\t\n" +
	"rc \tpdns-server\t4.8.3-4build3\n" +
	"ii \tphp8.4-fpm\t8.4.14-1+ubuntu24.04.1+deb.sury.org+1\n" +
	"ii \tphp7.4-fpm\t7.4.33-24+ubuntu24.04.1+deb.sury.org+1\n" +
	"ii \tphp8.10-fpm\t8.10.0-1\n" +
	"dpkg-query: no packages found matching pure-ftpd-mysql\n"

func TestParseSoftwareListsInstalledProgramsInOrder(t *testing.T) {
	want := []Software{
		{Name: "Nginx", Version: "1.24.0"},
		{Name: "Apache", Version: "2.4.58"},
		{Name: "MariaDB", Version: "10.11.13"},
		{Name: "Postfix", Version: "3.8.6"},
		{Name: "Dovecot", Version: "2.3.21"},
		{Name: "Redis", Version: "7.0.15"},
		{Name: "PHP 7.4", Version: "7.4.33"},
		{Name: "PHP 8.4", Version: "8.4.14"},
		{Name: "PHP 8.10", Version: "8.10.0"},
	}

	if got := parseSoftware(dpkgOutput); !reflect.DeepEqual(got, want) {
		t.Fatalf("parseSoftware =\n%v\nwant\n%v", got, want)
	}
}

func TestCollectSoftwareAsksDpkgAndKeepsWhatItListedOnExitCodeOne(t *testing.T) {
	var asked []string

	run := func(_ context.Context, program string, args ...string) ([]byte, error) {
		asked = append([]string{program}, args...)

		return []byte(dpkgOutput), errors.New("exit status 1")
	}

	software := collectSoftware(run)
	if len(software) != 9 {
		t.Fatalf("software = %v", software)
	}

	command := strings.Join(asked, " ")
	if !strings.HasPrefix(command, "/usr/bin/dpkg-query -W -f") || !strings.HasSuffix(command, "pure-ftpd-mysql php*-fpm") {
		t.Fatalf("asked %q", command)
	}
}

func TestCollectSoftwareWithoutDpkgIsEmpty(t *testing.T) {
	run := func(context.Context, string, ...string) ([]byte, error) {
		return nil, errors.New("no such file or directory")
	}

	if software := collectSoftware(run); len(software) != 0 {
		t.Fatalf("software = %v", software)
	}
}
