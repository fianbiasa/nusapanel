package webserver

import (
	"context"
	"errors"
	"testing"
	"time"
)

// mailEnv is a test environment whose clock is two months before the fake
// certificates expire.
func mailEnv(t *testing.T) *testEnv {
	t.Helper()

	e := newEnv(t)
	e.manager.cfg.Now = func() time.Time { return testExpiry.AddDate(0, -2, 0) }

	return e
}

const mailCertonly = "certbot certonly --webroot -w $BASE/var/www/rayxo-acme --cert-name rayxo-mail_example.com -d mail.example.com " +
	"--non-interactive --agree-tos --keep-until-expiring --register-unsafely-without-email"

func TestMailCertificateIsIssuedForMailDotDomain(t *testing.T) {
	e := mailEnv(t)
	e.reach(nil, "mail.example.com")

	certificate, err := e.manager.IssueMailCertificate(context.Background(), "example.com")
	if err != nil {
		t.Fatal(err)
	}

	if certificate.Name != "mail.example.com" || certificate.Source != MailSourceOwn || !certificate.ExpiresAt.Equal(testExpiry) {
		t.Fatalf("certificate = %+v", certificate)
	}

	e.assertCalls(mailCertonly)
	e.assertNoTokens()

	// Asked again while it is valid for long, certbot is not run.
	e.runner.reset()
	if _, err := e.manager.IssueMailCertificate(context.Background(), "example.com"); err != nil {
		t.Fatal(err)
	}

	e.assertCalls()
}

func TestMailCertificateUsesTheDomainsWildcard(t *testing.T) {
	e := mailEnv(t)
	e.writeCertificate("example.com", "example.com", "*.example.com")

	certificate, err := e.manager.IssueMailCertificate(context.Background(), "example.com")
	if err != nil {
		t.Fatal(err)
	}

	if certificate.Source != MailSourceDomain || certificate.CertificateFile != e.cfg.LetsEncryptDir+"/live/example.com/fullchain.pem" {
		t.Fatalf("certificate = %+v", certificate)
	}

	e.assertCalls()
}

func TestMailCertificateIgnoresAWebsiteCertificateWithoutMail(t *testing.T) {
	e := mailEnv(t)
	e.writeCertificate("example.com", "example.com", "www.example.com")
	e.reach(nil, "mail.example.com")

	certificate, err := e.manager.IssueMailCertificate(context.Background(), "example.com")
	if err != nil || certificate.Source != MailSourceOwn {
		t.Fatalf("certificate = %+v, %v", certificate, err)
	}

	e.assertCalls(mailCertonly)
}

func TestMailCertificateNeedsMailDotDomainToReachThisServer(t *testing.T) {
	e := mailEnv(t)

	_, err := e.manager.IssueMailCertificate(context.Background(), "example.com")
	if unreachable, ok := errors.AsType[*UnreachableError](err); !ok || unreachable.Domain != "mail.example.com" {
		t.Fatalf("err = %v", err)
	}

	e.assertCalls()
}

func TestMailCertificateIsDeletedWithoutTheDomains(t *testing.T) {
	e := mailEnv(t)
	e.writeCertificate("example.com", "example.com", "*.example.com")
	e.writeCertificate("rayxo-mail_example.com", "mail.example.com")

	if err := e.manager.DeleteMailCertificate(context.Background(), "example.com"); err != nil {
		t.Fatal(err)
	}

	e.assertCalls(deleteCall("rayxo-mail_example.com"))

	// The domain's wildcard is what serves mail.<domain> now.
	if certificate, err := e.manager.FindMailCertificate("example.com"); err != nil || certificate.Source != MailSourceDomain {
		t.Fatalf("certificate = %+v, %v", certificate, err)
	}

	if _, err := e.manager.FindMailCertificate("../x"); err == nil {
		t.Fatal("an invalid domain was looked up")
	}
}

func TestExpiredCertificatesDoNotServeMail(t *testing.T) {
	e := mailEnv(t)
	e.writeCertificate("rayxo-mail_example.com", "mail.example.com")
	e.manager.cfg.Now = func() time.Time { return testExpiry.Add(time.Hour) }

	if _, err := e.manager.FindMailCertificate("example.com"); !errors.Is(err, ErrNoCertificate) {
		t.Fatalf("err = %v", err)
	}
}
