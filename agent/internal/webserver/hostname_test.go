package webserver

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"slices"
	"testing"
)

const testHostname = "server1.example.net"

// newHostnameEnv is newEnv with the panel's certificate links and an FTP
// certificate file, the way setup.sh leaves a server whose Let's Encrypt
// request failed: a self-signed panel certificate.
func newHostnameEnv(t *testing.T) *testEnv {
	t.Helper()

	env := newEnv(t)
	env.cfg.PanelCertificateDir = filepath.Join(env.base, "etc/rayxo/panel-ssl")
	env.cfg.FTPCertificate = filepath.Join(env.base, "etc/ssl/private/pure-ftpd.pem")
	env.cfg.FTPUnit = "pure-ftpd-mysql.service"
	env.manager = New(env.cfg)

	env.writeCertificate("self-signed", testHostname)
	self := filepath.Join(env.cfg.LetsEncryptDir, "live", "self-signed")
	env.write(filepath.Join(env.cfg.PanelCertificateDir, "panel.crt"), env.read(filepath.Join(self, "fullchain.pem")))
	env.write(filepath.Join(env.cfg.PanelCertificateDir, "panel.key"), env.read(filepath.Join(self, "privkey.pem")))
	env.write(env.cfg.FTPCertificate, "self-signed key and certificate")

	return env
}

func TestServerHostnameIsTheMostQualifiedName(t *testing.T) {
	env := newEnv(t)

	if hostname, err := env.manager.ServerHostname(); err != nil || hostname != testHostname {
		t.Fatalf("ServerHostname() = %q, %v, want %q", hostname, err, testHostname)
	}

	env.cfg.Hostnames = func() []string { return []string{"localhost"} }
	env.manager = New(env.cfg)

	if _, err := env.manager.ServerHostname(); !errors.Is(err, ErrNoServerHostname) {
		t.Fatalf("a server without an FQDN: err = %v, want ErrNoServerHostname", err)
	}
}

func TestHostnameCertificateStatusTellsASelfSignedCertificateApart(t *testing.T) {
	env := newHostnameEnv(t)

	status, err := env.manager.HostnameCertificateStatus()
	if err != nil {
		t.Fatal(err)
	}

	if status.LetsEncrypt || status.Hostname != testHostname || !status.ExpiresAt.Equal(testExpiry) ||
		!slices.Equal(status.Names, []string{testHostname}) {
		t.Fatalf("unexpected status %+v", status)
	}
}

func TestIssueHostnameCertificateServesItToThePanelAndFTP(t *testing.T) {
	env := newHostnameEnv(t)
	// No DNS record in the test: the hostname is not checked first, since
	// servers often map their own name to 127.0.0.1 in /etc/hosts.
	env.runner.reset()

	status, err := env.manager.IssueHostnameCertificate(context.Background())
	if err != nil {
		t.Fatalf("issue failed: %v", err)
	}

	if !status.LetsEncrypt || !slices.Equal(status.Names, []string{testHostname}) || !status.ExpiresAt.Equal(testExpiry) {
		t.Fatalf("unexpected status %+v", status)
	}

	live := filepath.Join(env.cfg.LetsEncryptDir, "live", testHostname)
	for link, target := range map[string]string{"panel.crt": "fullchain.pem", "panel.key": "privkey.pem"} {
		if got, err := os.Readlink(filepath.Join(env.cfg.PanelCertificateDir, link)); err != nil || got != filepath.Join(live, target) {
			t.Fatalf("%s links to %q (%v), want %s", link, got, err, filepath.Join(live, target))
		}
	}

	want := env.read(filepath.Join(live, "privkey.pem")) + env.read(filepath.Join(live, "fullchain.pem"))
	if got := env.read(env.cfg.FTPCertificate); got != want {
		t.Fatalf("FTP certificate is not the hostname's key and chain")
	}

	if info, err := os.Stat(env.cfg.FTPCertificate); err != nil || info.Mode().Perm() != 0o600 {
		t.Fatalf("FTP certificate mode: %v %v, want 0600", info, err)
	}

	env.assertCalls(certonlyCall(testHostname), "nginx -t", "systemctl reload nginx", "systemctl try-restart pure-ftpd-mysql.service")
	env.assertNoTokens()

	// Asked again while the certificate is valid, certbot keeps it
	// (--keep-until-expiring) and nothing interrupts FTP.
	env.runner.certbot = func([]string) ([]byte, error) {
		return []byte("Certificate not yet due for renewal; no action taken.\n"), nil
	}
	env.runner.reset()

	if _, err := env.manager.IssueHostnameCertificate(context.Background()); err != nil {
		t.Fatalf("second issue failed: %v", err)
	}

	env.assertCalls(certonlyCall(testHostname), "nginx -t", "systemctl reload nginx")
}

func TestIssueHostnameCertificateLeavesAServerWithoutFTPAlone(t *testing.T) {
	env := newHostnameEnv(t)

	if err := os.Remove(env.cfg.FTPCertificate); err != nil {
		t.Fatal(err)
	}

	env.runner.reset()

	if _, err := env.manager.IssueHostnameCertificate(context.Background()); err != nil {
		t.Fatalf("issue failed: %v", err)
	}

	env.assertCalls(certonlyCall(testHostname), "nginx -t", "systemctl reload nginx")
	env.assertMissing(env.cfg.FTPCertificate)
}

func TestIssueHostnameCertificateReportsCertbotFailures(t *testing.T) {
	env := newHostnameEnv(t)
	env.runner.certbot = certbotFailing("An unexpected error occurred:\nurn:ietf:params:acme:error:rateLimited :: too many certificates")

	_, err := env.manager.IssueHostnameCertificate(context.Background())

	if issue, ok := errors.AsType[*IssueError](err); !ok || !issue.RateLimited {
		t.Fatalf("err = %v, want a rate-limited *IssueError", err)
	}

	if _, err := os.Readlink(filepath.Join(env.cfg.PanelCertificateDir, "panel.crt")); err == nil {
		t.Fatal("the panel certificate was linked although certbot failed")
	}
}
