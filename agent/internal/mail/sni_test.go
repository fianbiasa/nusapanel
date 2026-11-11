package mail

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
)

type sniEnv struct {
	t       *testing.T
	base    string
	manager *Manager
	cfg     Config
	calls   []string
	// failing names a program that fails.
	failing string
}

func newSNIEnv(t *testing.T) *sniEnv {
	t.Helper()

	e := &sniEnv{t: t, base: t.TempDir()}
	e.cfg = Config{
		SNIDir:         filepath.Join(e.base, "ssl", "mail"),
		DovecotSNIFile: filepath.Join(e.base, "rayxo-sni.conf"),
		PostfixSNIMap:  filepath.Join(e.base, "sni"),
		Run: func(_ context.Context, program string, args ...string) ([]byte, error) {
			e.calls = append(e.calls, strings.Join(append([]string{filepath.Base(program)}, args...), " "))

			if filepath.Base(program) == e.failing {
				return []byte("doveconf: Fatal: Error in configuration file"), errors.New("exit status 89")
			}

			return nil, nil
		},
	}
	e.manager = New(e.cfg)

	return e
}

// pemFiles writes a certificate and key file for name.
func (e *sniEnv) pemFiles(name string) SNICertificate {
	e.t.Helper()

	certificate := filepath.Join(e.base, "live", name, "fullchain.pem")
	key := filepath.Join(e.base, "live", name, "privkey.pem")

	if err := os.MkdirAll(filepath.Dir(certificate), 0o755); err != nil {
		e.t.Fatal(err)
	}

	for path, kind := range map[string]string{certificate: "CERTIFICATE", key: "EC PRIVATE KEY"} {
		if err := os.WriteFile(path, []byte("-----BEGIN "+kind+"-----\nAAAA\n-----END "+kind+"-----\n"), 0o644); err != nil {
			e.t.Fatal(err)
		}
	}

	return SNICertificate{Name: name, CertificateFile: certificate, KeyFile: key}
}

func (e *sniEnv) read(path string) string {
	e.t.Helper()

	content, err := os.ReadFile(path)
	if err != nil {
		e.t.Fatal(err)
	}

	return string(content)
}

func TestApplySNIServesCopiesOfTheCertificates(t *testing.T) {
	e := newSNIEnv(t)

	err := e.manager.ApplySNI(context.Background(), []string{"example.com", "other.example"}, []SNICertificate{e.pemFiles("mail.example.com")})
	if err != nil {
		t.Fatal(err)
	}

	copied := filepath.Join(e.cfg.SNIDir, "mail.example.com")
	for _, path := range []string{copied + ".crt", copied + ".key"} {
		info, err := os.Stat(path)
		if err != nil || info.Mode().Perm() != 0o600 {
			t.Fatalf("%s: %v, %v", path, info, err)
		}
	}

	dovecot := e.read(e.cfg.DovecotSNIFile)
	if !strings.Contains(dovecot, "local_name mail.example.com {\n  ssl_cert = <"+copied+".crt\n  ssl_key = <"+copied+".key\n}") || strings.Contains(dovecot, "other.example") {
		t.Fatalf("dovecot:\n%s", dovecot)
	}

	if postfix := e.read(e.cfg.PostfixSNIMap); !strings.Contains(postfix, "\nmail.example.com "+copied+".key "+copied+".crt\n") {
		t.Fatalf("postfix:\n%s", postfix)
	}

	want := []string{"doveconf -n", "postmap -F hash:" + e.cfg.PostfixSNIMap, "doveadm reload"}
	if !slices.Equal(e.calls, want) {
		t.Fatalf("calls = %q", e.calls)
	}

	// Both domains are kept for a refresh, also the one without a certificate.
	domains, err := e.manager.SNIDomains()
	if err != nil || !slices.Equal(domains, []string{"example.com", "other.example"}) {
		t.Fatalf("domains = %v, %v", domains, err)
	}

	// Without the certificate, its copies go.
	if err := e.manager.ApplySNI(context.Background(), []string{"example.com"}, nil); err != nil {
		t.Fatal(err)
	}

	if _, err := os.Stat(copied + ".key"); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("the copy of the key stayed: %v", err)
	}

	if strings.Contains(e.read(e.cfg.DovecotSNIFile), "local_name") {
		t.Fatal("Dovecot still serves the name")
	}
}

func TestApplySNIKeepsTheLastConfigurationDovecotTook(t *testing.T) {
	e := newSNIEnv(t)

	if err := e.manager.ApplySNI(context.Background(), []string{"example.com"}, []SNICertificate{e.pemFiles("mail.example.com")}); err != nil {
		t.Fatal(err)
	}

	before := e.read(e.cfg.DovecotSNIFile)
	e.failing = "doveconf"
	e.calls = nil

	err := e.manager.ApplySNI(context.Background(), []string{"example.com", "other.example"},
		[]SNICertificate{e.pemFiles("mail.example.com"), e.pemFiles("mail.other.example")})
	if err == nil {
		t.Fatal("a configuration Dovecot refused was kept")
	}

	if after := e.read(e.cfg.DovecotSNIFile); after != before {
		t.Fatalf("dovecot:\n%s", after)
	}

	if !slices.Equal(e.calls, []string{"doveconf -n"}) {
		t.Fatalf("calls = %q", e.calls)
	}
}

func TestApplySNIRefusesWhatItWasNotAskedFor(t *testing.T) {
	e := newSNIEnv(t)

	stranger := e.pemFiles("mail.other.example")
	if err := e.manager.ApplySNI(context.Background(), []string{"example.com"}, []SNICertificate{stranger}); err == nil {
		t.Fatal("a certificate for a name of another domain was served")
	}

	if err := e.manager.ApplySNI(context.Background(), []string{"../x"}, nil); err == nil {
		t.Fatal("an invalid domain was taken")
	}

	broken := e.pemFiles("mail.example.com")
	if err := os.WriteFile(broken.KeyFile, []byte("not a key"), 0o600); err != nil {
		t.Fatal(err)
	}

	if err := e.manager.ApplySNI(context.Background(), []string{"example.com"}, []SNICertificate{broken}); err == nil {
		t.Fatal("a key file without a key was served")
	}

	if len(e.calls) != 0 {
		t.Fatalf("calls = %q", e.calls)
	}
}
