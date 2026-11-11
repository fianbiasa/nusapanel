package mail

import (
	"context"
	"encoding/pem"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"sync"

	"rayxo/agent/internal/validate"
)

// TLS for mail.<domain>: IMAP, POP3 and SMTP clients that ask for
// mail.<domain> (SNI) get its certificate instead of the hostname's.
// Dovecot (a local_name block per name) and Postfix (tls_server_sni_maps,
// built with postmap -F) read copies of the certificates in SNIDir, so a
// certificate deleted or replaced elsewhere never leaves them with a file
// that is gone, which would keep Dovecot from starting. The copies are
// made again on every apply, including the refresh certbot's renewal hook
// asks for.

const (
	doveconfProgram = "/usr/bin/doveconf"
	postmapProgram  = "/usr/sbin/postmap"
	// sniDomainsFile, in SNIDir, lists the domains last applied, for a
	// refresh.
	sniDomainsFile = "domains"
	// MaxSNIDomains is the most domains one apply takes.
	MaxSNIDomains = 100000
)

// sniMu keeps applies from writing the files at the same time.
var sniMu sync.Mutex

// SNICertificate is the certificate chain and key files that serve Name.
type SNICertificate struct {
	Name            string
	CertificateFile string
	KeyFile         string
}

// SNIDomains returns the domains the last ApplySNI was given.
func (m *Manager) SNIDomains() ([]string, error) {
	content, err := os.ReadFile(filepath.Join(m.cfg.SNIDir, sniDomainsFile))
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}

	if err != nil {
		return nil, err
	}

	var domains []string

	for line := range strings.Lines(string(content)) {
		domain := strings.TrimSpace(line)
		if domain != "" && validate.Domain(domain) == nil {
			domains = append(domains, domain)
		}
	}

	return domains, nil
}

// ApplySNI makes Dovecot and Postfix serve each of certificates for its
// name, and remembers domains (whose mail.<domain> they are, also those
// without a certificate yet) for a later refresh. Names without a
// certificate get the hostname's, as before.
func (m *Manager) ApplySNI(ctx context.Context, domains []string, certificates []SNICertificate) error {
	sniMu.Lock()
	defer sniMu.Unlock()

	if len(domains) > MaxSNIDomains {
		return fmt.Errorf("at most %d domains", MaxSNIDomains)
	}

	names := map[string]bool{}

	for _, domain := range domains {
		if err := validate.Domain(domain); err != nil {
			return err
		}

		names["mail."+domain] = true
	}

	certificates = slices.Clone(certificates)
	slices.SortFunc(certificates, func(a, b SNICertificate) int { return strings.Compare(a.Name, b.Name) })

	for _, certificate := range certificates {
		if !names[certificate.Name] {
			return fmt.Errorf("%s is not mail.<domain> of a given domain", certificate.Name)
		}
	}

	if err := os.MkdirAll(m.cfg.SNIDir, 0o700); err != nil {
		return err
	}

	if err := os.Chmod(m.cfg.SNIDir, 0o700); err != nil {
		return err
	}

	// The copies first: the configuration in use may name some of them,
	// which only get valid files.
	keep := map[string]bool{sniDomainsFile: true}

	for _, certificate := range certificates {
		if err := m.copyPEM(certificate.CertificateFile, m.sniFile(certificate.Name, ".crt"), "CERTIFICATE"); err != nil {
			return fmt.Errorf("certificate of %s: %w", certificate.Name, err)
		}

		if err := m.copyPEM(certificate.KeyFile, m.sniFile(certificate.Name, ".key"), "PRIVATE KEY"); err != nil {
			return fmt.Errorf("key of %s: %w", certificate.Name, err)
		}

		keep[certificate.Name+".crt"], keep[certificate.Name+".key"] = true, true
	}

	if err := m.applyDovecotSNI(ctx, certificates); err != nil {
		return err
	}

	if err := m.applyPostfixSNI(ctx, certificates); err != nil {
		return err
	}

	if _, err := m.cfg.Run(ctx, doveadmProgram, "reload"); err != nil {
		return fmt.Errorf("reload Dovecot: %w", err)
	}

	// Copies no configuration names any more.
	entries, err := os.ReadDir(m.cfg.SNIDir)
	if err != nil {
		return err
	}

	for _, entry := range entries {
		if !keep[entry.Name()] {
			if err := os.Remove(filepath.Join(m.cfg.SNIDir, entry.Name())); err != nil && !errors.Is(err, os.ErrNotExist) {
				return err
			}
		}
	}

	list := slices.Compact(slices.Sorted(slices.Values(domains)))
	text := strings.Join(list, "\n")
	if text != "" {
		text += "\n"
	}

	return m.writeFile(filepath.Join(m.cfg.SNIDir, sniDomainsFile), []byte(text), 0o600, -1)
}

// applyDovecotSNI writes Dovecot's local_name blocks; a configuration
// doveconf refuses is taken back.
func (m *Manager) applyDovecotSNI(ctx context.Context, certificates []SNICertificate) error {
	var text strings.Builder

	text.WriteString("# Written by rayxod (mail.sni_apply); changes are overwritten.\n")

	for _, certificate := range certificates {
		fmt.Fprintf(&text, "local_name %s {\n  ssl_cert = <%s\n  ssl_key = <%s\n}\n",
			certificate.Name, m.sniFile(certificate.Name, ".crt"), m.sniFile(certificate.Name, ".key"))
	}

	previous, readErr := os.ReadFile(m.cfg.DovecotSNIFile)

	if err := m.writeFile(m.cfg.DovecotSNIFile, []byte(text.String()), 0o644, -1); err != nil {
		return err
	}

	if _, err := m.cfg.Run(ctx, doveconfProgram, "-n"); err != nil {
		if readErr == nil {
			_ = m.writeFile(m.cfg.DovecotSNIFile, previous, 0o644, -1)
		} else {
			_ = os.Remove(m.cfg.DovecotSNIFile)
		}

		return fmt.Errorf("Dovecot refused the certificates: %w", err)
	}

	return nil
}

// applyPostfixSNI writes Postfix's SNI table: each name with its key and
// chain, which postmap -F stores in the table itself.
func (m *Manager) applyPostfixSNI(ctx context.Context, certificates []SNICertificate) error {
	var text strings.Builder

	text.WriteString("# Written by rayxod (mail.sni_apply); changes are overwritten.\n")

	for _, certificate := range certificates {
		fmt.Fprintf(&text, "%s %s %s\n", certificate.Name, m.sniFile(certificate.Name, ".key"), m.sniFile(certificate.Name, ".crt"))
	}

	if err := m.writeFile(m.cfg.PostfixSNIMap, []byte(text.String()), 0o600, -1); err != nil {
		return err
	}

	if _, err := m.cfg.Run(ctx, postmapProgram, "-F", "hash:"+m.cfg.PostfixSNIMap); err != nil {
		return fmt.Errorf("build Postfix's SNI table: %w", err)
	}

	// The table holds private keys.
	if err := os.Chmod(m.cfg.PostfixSNIMap+".db", 0o600); err != nil && !errors.Is(err, os.ErrNotExist) {
		return err
	}

	return nil
}

// copyPEM copies the PEM file from to to (0600), when it holds a block
// whose type ends in kind.
func (m *Manager) copyPEM(from, to, kind string) error {
	content, err := os.ReadFile(from)
	if err != nil {
		return err
	}

	block, _ := pem.Decode(content)
	if block == nil || !strings.HasSuffix(block.Type, kind) {
		return fmt.Errorf("%s holds no %s", from, strings.ToLower(kind))
	}

	return m.writeFile(to, content, 0o600, -1)
}

func (m *Manager) sniFile(name, extension string) string {
	return filepath.Join(m.cfg.SNIDir, name+extension)
}
