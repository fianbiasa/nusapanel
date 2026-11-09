package webserver

import (
	"context"
	"crypto"
	"crypto/ecdsa"
	"crypto/ed25519"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/rsa"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"errors"
	"math/big"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"
)

// testPKI is a root CA, an intermediate it signed, and helpers to sign
// leaf certificates the way a real CA would.
type testPKI struct {
	t            *testing.T
	root         *x509.Certificate
	rootKey      crypto.Signer
	intermediate *x509.Certificate
	interKey     crypto.Signer
	roots        *x509.CertPool
}

// testNow is the time custom certificates are checked at.
var testNow = time.Date(2026, 11, 9, 12, 0, 0, 0, time.UTC)

func newTestPKI(t *testing.T) *testPKI {
	t.Helper()

	pki := &testPKI{t: t, rootKey: newECKey(t), interKey: newECKey(t)}

	rootTemplate := &x509.Certificate{
		SerialNumber:          big.NewInt(1),
		Subject:               pkix.Name{CommonName: "Test Root"},
		NotBefore:             testNow.AddDate(-5, 0, 0),
		NotAfter:              testNow.AddDate(5, 0, 0),
		IsCA:                  true,
		BasicConstraintsValid: true,
		KeyUsage:              x509.KeyUsageCertSign,
	}
	pki.root = pki.create(rootTemplate, rootTemplate, pki.rootKey.Public(), pki.rootKey)

	interTemplate := &x509.Certificate{
		SerialNumber:          big.NewInt(2),
		Subject:               pkix.Name{CommonName: "Test Intermediate R1"},
		NotBefore:             testNow.AddDate(-1, 0, 0),
		NotAfter:              testNow.AddDate(2, 0, 0),
		IsCA:                  true,
		BasicConstraintsValid: true,
		KeyUsage:              x509.KeyUsageCertSign,
	}
	pki.intermediate = pki.create(interTemplate, pki.root, pki.interKey.Public(), pki.rootKey)

	pki.roots = x509.NewCertPool()
	pki.roots.AddCert(pki.root)

	return pki
}

func newECKey(t *testing.T) *ecdsa.PrivateKey {
	t.Helper()

	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}

	return key
}

func (p *testPKI) create(template, parent *x509.Certificate, public crypto.PublicKey, signer crypto.Signer) *x509.Certificate {
	p.t.Helper()

	der, err := x509.CreateCertificate(rand.Reader, template, parent, public, signer)
	if err != nil {
		p.t.Fatal(err)
	}

	certificate, err := x509.ParseCertificate(der)
	if err != nil {
		p.t.Fatal(err)
	}

	return certificate
}

// leaf returns a certificate for names signed by the intermediate, valid
// from notBefore to notAfter, for the public half of key.
func (p *testPKI) leaf(key crypto.Signer, notBefore, notAfter time.Time, names ...string) *x509.Certificate {
	p.t.Helper()

	return p.create(&x509.Certificate{
		SerialNumber: big.NewInt(time.Now().UnixNano()),
		Subject:      pkix.Name{CommonName: names[0]},
		DNSNames:     names,
		NotBefore:    notBefore,
		NotAfter:     notAfter,
		KeyUsage:     x509.KeyUsageDigitalSignature,
		ExtKeyUsage:  []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
	}, p.intermediate, key.Public(), p.interKey)
}

func certificatesPEM(certificates ...*x509.Certificate) string {
	var out strings.Builder

	for _, certificate := range certificates {
		out.Write(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: certificate.Raw}))
	}

	return out.String()
}

func keyPEM(t *testing.T, key crypto.Signer, blockType string) string {
	t.Helper()

	var (
		der []byte
		err error
	)

	switch blockType {
	case "PRIVATE KEY":
		der, err = x509.MarshalPKCS8PrivateKey(key)
	case "RSA PRIVATE KEY":
		der = x509.MarshalPKCS1PrivateKey(key.(*rsa.PrivateKey))
	case "EC PRIVATE KEY":
		der, err = x509.MarshalECPrivateKey(key.(*ecdsa.PrivateKey))
	}

	if err != nil {
		t.Fatal(err)
	}

	return string(pem.EncodeToMemory(&pem.Block{Type: blockType, Bytes: der}))
}

// newCustomEnv is a test environment that checks custom certificates
// against pki's root at testNow, with example.com applied for budi.
func newCustomEnv(t *testing.T, pki *testPKI) *testEnv {
	t.Helper()

	env := newEnv(t)
	env.cfg.CertificateRoots = pki.roots
	env.cfg.Now = func() time.Time { return testNow }
	env.manager = New(env.cfg)
	env.mustApply(budiSite)
	env.runner.reset()

	return env
}

func (e *testEnv) install(domain, certificate, key string) (CustomCertificate, error) {
	return e.manager.InstallCertificate(context.Background(), "budi", domain, certificate, key)
}

func TestInstallCertificateWritesATrustedChainForRootOnly(t *testing.T) {
	pki := newTestPKI(t)
	env := newCustomEnv(t, pki)
	key := newECKey(t)
	leaf := pki.leaf(key, testNow.AddDate(0, -1, 0), testNow.AddDate(1, 0, 0), "example.com", "www.example.com")

	// Extra spacing around the blocks is fine; EC keys in their own
	// format too.
	certificate := "  \n" + certificatesPEM(leaf) + "\n\n" + certificatesPEM(pki.intermediate) + "\n"

	installed, err := env.install("example.com", certificate, keyPEM(t, key, "EC PRIVATE KEY"))
	if err != nil {
		t.Fatalf("install failed: %v", err)
	}

	if !installed.Trusted || installed.Issuer != "Test Intermediate R1" ||
		!slices.Equal(installed.Names, []string{"example.com", "www.example.com"}) ||
		!installed.ExpiresAt.Equal(leaf.NotAfter.UTC()) {
		t.Fatalf("installed = %+v", installed)
	}

	dir := filepath.Join(env.cfg.CustomCertificateDir, "example.com")

	for path, mode := range map[string]os.FileMode{
		env.cfg.CustomCertificateDir:        0o700,
		dir:                                 0o700,
		filepath.Join(dir, "privkey.pem"):   0o600,
		filepath.Join(dir, "fullchain.pem"): 0o644,
	} {
		info, err := os.Stat(path)
		if err != nil || info.Mode().Perm() != mode {
			t.Fatalf("%s: %v %v, want mode %v", path, info, err, mode)
		}
	}

	// Stored re-encoded: the chain alone, and the key as PKCS #8.
	if chain := env.read(filepath.Join(dir, "fullchain.pem")); chain != certificatesPEM(leaf, pki.intermediate) {
		t.Fatalf("unexpected chain:\n%s", chain)
	}

	storedKey := env.read(filepath.Join(dir, "privkey.pem"))
	if !strings.HasPrefix(storedKey, "-----BEGIN PRIVATE KEY-----\n") || strings.Count(storedKey, "BEGIN") != 1 {
		t.Fatalf("unexpected key file:\n%s", storedKey)
	}

	if !env.manager.HasCustomCertificate("example.com") {
		t.Fatal("HasCustomCertificate is false after installing")
	}

	// Installing changes no web server configuration, and never logs the key.
	env.assertCalls()
	assertNotContains(t, "agent log", env.logs.String(), "PRIVATE KEY", storedKey)
}

func TestInstallCertificateAcceptsAnUntrustedOriginCertificate(t *testing.T) {
	pki := newTestPKI(t)
	env := newCustomEnv(t, pki)

	// Like a Cloudflare Origin Certificate: signed by a CA browsers do not
	// know, and only fine behind Cloudflare.
	origin := newTestPKI(t)

	key, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		t.Fatal(err)
	}

	leaf := origin.leaf(key, testNow.AddDate(0, -1, 0), testNow.AddDate(15, 0, 0), "*.example.com", "example.com")

	installed, err := env.install("example.com", certificatesPEM(leaf), keyPEM(t, key, "RSA PRIVATE KEY"))
	if err != nil {
		t.Fatalf("install failed: %v", err)
	}

	if installed.Trusted {
		t.Fatal("a certificate of an unknown CA counts as trusted")
	}
}

func TestInstallCertificateAcceptsAWildcardForASubdomain(t *testing.T) {
	pki := newTestPKI(t)
	env := newCustomEnv(t, pki)

	subdomain := budiSite
	subdomain.Domain = "blog.example.com"
	env.mustApply(subdomain)

	key := newECKey(t)
	leaf := pki.leaf(key, testNow.AddDate(0, -1, 0), testNow.AddDate(1, 0, 0), "*.example.com")
	certificate := certificatesPEM(leaf, pki.intermediate)

	if _, err := env.install("blog.example.com", certificate, keyPEM(t, key, "PRIVATE KEY")); err != nil {
		t.Fatalf("install failed: %v", err)
	}

	// The wildcard does not cover the bare domain.
	var refused *CertificateError
	if _, err := env.install("example.com", certificate, keyPEM(t, key, "PRIVATE KEY")); !errors.As(err, &refused) ||
		refused.Reason != "the certificate does not cover example.com" {
		t.Fatalf("got %v", err)
	}
}

func TestInstallCertificateRefusesWhatCannotServeTheDomain(t *testing.T) {
	pki := newTestPKI(t)
	key := newECKey(t)
	valid := pki.leaf(key, testNow.AddDate(0, -1, 0), testNow.AddDate(1, 0, 0), "example.com")
	expired := pki.leaf(key, testNow.AddDate(-1, 0, 0), testNow.AddDate(0, 0, -1), "example.com")
	future := pki.leaf(key, testNow.AddDate(0, 0, 1), testNow.AddDate(1, 0, 0), "example.com")
	other := pki.leaf(key, testNow.AddDate(0, -1, 0), testNow.AddDate(1, 0, 0), "example.org")

	weakKey, err := rsa.GenerateKey(rand.Reader, 1024)
	if err != nil {
		t.Fatal(err)
	}

	weak := pki.leaf(weakKey, testNow.AddDate(0, -1, 0), testNow.AddDate(1, 0, 0), "example.com")

	_, edKey, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}

	goodKey := keyPEM(t, key, "PRIVATE KEY")
	encrypted := "-----BEGIN EC PRIVATE KEY-----\nProc-Type: 4,ENCRYPTED\nDEK-Info: AES-128-CBC,00000000000000000000000000000000\n\nAAAA\n-----END EC PRIVATE KEY-----\n"

	cases := map[string]struct{ certificate, key, reason string }{
		"another key":        {certificatesPEM(valid), keyPEM(t, newECKey(t), "PRIVATE KEY"), "the private key does not belong to the certificate"},
		"another domain":     {certificatesPEM(other), goodKey, "the certificate does not cover example.com"},
		"expired":            {certificatesPEM(expired), goodKey, "the certificate expired on 2026-11-08"},
		"not valid yet":      {certificatesPEM(future), goodKey, "the certificate is only valid from 2026-11-10"},
		"weak RSA key":       {certificatesPEM(weak), keyPEM(t, weakKey, "PRIVATE KEY"), "the RSA key has 1024 bits; at least 2048 are needed"},
		"Ed25519 key":        {certificatesPEM(valid), keyPEM(t, edKey, "PRIVATE KEY"), "only RSA and ECDSA keys are supported"},
		"encrypted key":      {certificatesPEM(valid), encrypted, "the private key is protected by a password; remove the password first"},
		"PKCS #8 encrypted":  {certificatesPEM(valid), "-----BEGIN ENCRYPTED PRIVATE KEY-----\nAAAA\n-----END ENCRYPTED PRIVATE KEY-----\n", "the private key is protected by a password; remove the password first"},
		"key as certificate": {certificatesPEM(valid) + goodKey, goodKey, "the certificate field may only hold certificates, not a private key"},
		"certificate as key": {certificatesPEM(valid), certificatesPEM(valid), "the private key field holds a certificate, not a private key"},
		"two keys":           {certificatesPEM(valid), goodKey + goodKey, "the private key field may only hold one key"},
		"not PEM":            {"MIIB...", goodKey, "the certificate is not in PEM format (-----BEGIN CERTIFICATE-----)"},
		"empty certificate":  {"  \n ", goodKey, "there is no certificate"},
		"broken certificate": {"-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n", goodKey, "certificate 1 cannot be read"},
		"key not PEM":        {certificatesPEM(valid), "secret", "the private key is not in PEM format (-----BEGIN PRIVATE KEY-----)"},
		"broken key":         {certificatesPEM(valid), "-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----\n", "the private key cannot be read"},
		"huge certificate":   {strings.Repeat("A", maxCertificateBytes+1), goodKey, "the certificate is larger than 64 KB"},
		"chain of eleven":    {strings.Repeat(certificatesPEM(valid), maxChainLength+1), goodKey, "the chain has more than 10 certificates"},
	}

	for name, tc := range cases {
		t.Run(name, func(t *testing.T) {
			env := newCustomEnv(t, pki)

			_, err := env.install("example.com", tc.certificate, tc.key)

			var refused *CertificateError
			if !errors.As(err, &refused) || refused.Reason != tc.reason {
				t.Fatalf("got %v, want %q", err, tc.reason)
			}

			env.assertMissing(filepath.Join(env.cfg.CustomCertificateDir, "example.com"))
		})
	}
}

func TestInstallCertificateNeedsTheDomainOfTheAccount(t *testing.T) {
	pki := newTestPKI(t)
	env := newCustomEnv(t, pki)
	key := newECKey(t)
	leaf := pki.leaf(key, testNow.AddDate(0, -1, 0), testNow.AddDate(1, 0, 0), "budi.id")

	if _, err := env.install("budi.id", certificatesPEM(leaf), keyPEM(t, key, "PRIVATE KEY")); !errors.Is(err, ErrDomainNotSetUp) {
		t.Fatalf("got %v, want ErrDomainNotSetUp", err)
	}

	if _, err := env.manager.InstallCertificate(context.Background(), "budi", "../etc", "x", "y"); err == nil {
		t.Fatal("an invalid domain was accepted")
	}
}

// installValid installs a valid custom certificate for domain.
func (e *testEnv) installValid(pki *testPKI, domain string) {
	e.t.Helper()

	key := newECKey(e.t)
	leaf := pki.leaf(key, testNow.AddDate(0, -1, 0), testNow.AddDate(1, 0, 0), domain)

	if _, err := e.install(domain, certificatesPEM(leaf, pki.intermediate), keyPEM(e.t, key, "PRIVATE KEY")); err != nil {
		e.t.Fatalf("install failed: %v", err)
	}
}

func TestSwitchingBetweenLetsEncryptAndACustomCertificate(t *testing.T) {
	pki := newTestPKI(t)
	env := newCustomEnv(t, pki)
	env.writeCertificate("example.com", "example.com", "www.example.com")
	env.mustApply(sslSite(true))
	env.installValid(pki, "example.com")
	env.runner.reset()

	custom := sslSite(true)
	custom.CustomCertificate = true
	env.mustApply(custom)

	dir := filepath.Join(env.cfg.CustomCertificateDir, "example.com")
	nginx := env.read(env.nginxVhost("budi", "example.com"))
	assertContains(t, "nginx vhost", nginx,
		"ssl_certificate "+dir+"/fullchain.pem;",
		"ssl_certificate_key "+dir+"/privkey.pem;",
		"return 301 https://$host$request_uri;",
	)
	assertNotContains(t, "nginx vhost", nginx, env.cfg.LetsEncryptDir)

	// The Let's Encrypt certificate it no longer uses is gone, so certbot
	// stops renewing it.
	env.assertCalls(append(append([]string{"systemctl is-active --quiet nusa-php84@budi.service"}, reloadBoth...), deleteCall("example.com"))...)

	// And back: the custom certificate goes.
	env.writeCertificate("example.com", "example.com", "www.example.com")
	env.runner.reset()
	env.mustApply(sslSite(true))

	assertContains(t, "nginx vhost", env.read(env.nginxVhost("budi", "example.com")),
		"ssl_certificate "+env.cfg.LetsEncryptDir+"/live/example.com/fullchain.pem;")
	env.assertMissing(dir)
}

func TestApplyingACustomCertificateThatIsNotInstalledFails(t *testing.T) {
	pki := newTestPKI(t)
	env := newCustomEnv(t, pki)

	custom := sslSite(false)
	custom.CustomCertificate = true

	if _, err := env.apply(custom); !errors.Is(err, ErrNoCertificate) {
		t.Fatalf("got %v, want ErrNoCertificate", err)
	}

	without := budiSite
	without.CustomCertificate = true

	if err := without.Validate(); err == nil {
		t.Fatal("a custom certificate without SSL was accepted")
	}
}

func TestDeleteCertificateRemovesACustomOneOnceUnused(t *testing.T) {
	pki := newTestPKI(t)
	env := newCustomEnv(t, pki)
	env.installValid(pki, "example.com")

	custom := sslSite(false)
	custom.CustomCertificate = true
	env.mustApply(custom)

	if err := env.manager.DeleteCertificate(context.Background(), "budi", "example.com"); !errors.Is(err, ErrCertificateInUse) {
		t.Fatalf("got %v, want ErrCertificateInUse", err)
	}

	env.mustApply(budiSite)

	if err := env.manager.DeleteCertificate(context.Background(), "budi", "example.com"); err != nil {
		t.Fatalf("delete failed: %v", err)
	}

	env.assertMissing(filepath.Join(env.cfg.CustomCertificateDir, "example.com"))
}

func TestRemoveDomainDeletesItsCustomCertificate(t *testing.T) {
	pki := newTestPKI(t)
	env := newCustomEnv(t, pki)
	env.installValid(pki, "example.com")

	if err := env.manager.RemoveDomain(context.Background(), "budi", "example.com"); err != nil {
		t.Fatalf("remove failed: %v", err)
	}

	env.assertMissing(filepath.Join(env.cfg.CustomCertificateDir, "example.com"))
}
