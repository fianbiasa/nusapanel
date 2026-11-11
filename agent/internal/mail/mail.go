// Package mail prepares the email of hosting accounts' domains: the folder
// their mailboxes live in, /home/<account>/imap/<domain> (the account's, so
// mail counts towards its disk quota), and the DKIM key Rspamd signs the
// domain's mail with. Postfix and Dovecot read mail domains and mailboxes
// from the panel's database themselves, and Dovecot makes each mailbox's
// folder the first time mail arrives.
//
// Mail is only ever deleted by the files helper, which runs as the
// account inside its home, so nothing in a home can turn a deletion
// against the rest of the server.
package mail

import (
	"context"
	"crypto/rand"
	"crypto/rsa"
	"crypto/x509"
	"encoding/base64"
	"encoding/pem"
	"errors"
	"fmt"
	"os"
	"os/user"
	"path/filepath"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"unicode/utf8"

	"rayxo/agent/internal/files"
	"rayxo/agent/internal/runner"
	"rayxo/agent/internal/sysuser"
	"rayxo/agent/internal/validate"
)

const (
	// keyBits is the size of the DKIM keys made here.
	keyBits = 2048
	// imapDir holds the mail of an account, under its home.
	imapDir = "imap"
	// MaxLocalPart is the longest part of an address before the @.
	MaxLocalPart = 64
	// SieveFile is the Sieve script of a mailbox, in its folder: the
	// panel's filters and autoreply. Dovecot runs it on delivery, after the
	// server's own script (spam into Junk).
	SieveFile = "rayxo.sieve"
	// SieveBinary is what Dovecot compiles SieveFile to.
	SieveBinary = "rayxo.svbin"
	// MaxSieveBytes is the largest script the panel may write.
	MaxSieveBytes = 64 << 10
)

var (
	selectorPattern  = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$`)
	localPartPattern = regexp.MustCompile(`^[a-z0-9_+-]+(\.[a-z0-9_+-]+)*$`)
)

// ValidateSelector returns an error unless selector is a DKIM selector: one
// lowercase DNS label.
func ValidateSelector(selector string) error {
	if !selectorPattern.MatchString(selector) {
		return fmt.Errorf("selector %q must be one lowercase DNS label", selector)
	}

	return nil
}

// ValidateLocalPart returns an error unless localPart is the part of a
// mailbox's address before the @: lowercase letters, digits and "_+-",
// with dots only between them.
func ValidateLocalPart(localPart string) error {
	if len(localPart) > MaxLocalPart || !localPartPattern.MatchString(localPart) {
		return fmt.Errorf("local part %q must be lowercase letters, digits and _+- with dots between them, at most %d characters", localPart, MaxLocalPart)
	}

	return nil
}

// ValidateSieve returns an error unless script can be a mailbox's Sieve
// script: UTF-8 text without NUL bytes, at most MaxSieveBytes. Empty means
// no script. Dovecot itself refuses a script that does not compile, and
// only runs it for the mailbox it belongs to.
func ValidateSieve(script string) error {
	if len(script) > MaxSieveBytes {
		return fmt.Errorf("the Sieve script may have at most %d bytes", MaxSieveBytes)
	}

	if !utf8.ValidString(script) || strings.ContainsRune(script, 0) {
		return errors.New("the Sieve script must be UTF-8 text")
	}

	return nil
}

// Config says where the DKIM keys go and how the system is changed.
type Config struct {
	// DKIMDir holds <domain>.<selector>.key, readable by KeyGroup.
	DKIMDir string
	// SelectorMap is Rspamd's map of "<domain> <selector>" lines.
	SelectorMap string
	// SendLimitMap is the map of daily send limits Rspamd's ratelimit
	// module reads (ApplySendLimits), readable by KeyGroup.
	SendLimitMap string
	// MailnamePath holds the server's mail name, the domain of mail its
	// local users send under their login name.
	MailnamePath string
	// SNIDir holds the copies of the certificates Dovecot and Postfix serve
	// for mail.<domain> (ApplySNI), root only.
	SNIDir string
	// DovecotSNIFile is the file dovecot.conf includes with the local_name
	// blocks; PostfixSNIMap is Postfix's tls_server_sni_maps source.
	DovecotSNIFile string
	PostfixSNIMap  string
	// Run runs a program (doveadm); nil uses runner.Run.
	Run func(ctx context.Context, program string, args ...string) ([]byte, error)
	// KeyGroup is the group of the DKIM keys: Rspamd's.
	KeyGroup string
	// LookupGroup returns the gid of a group; nil uses the system's.
	LookupGroup func(name string) (int, error)
	// Chown changes the owner of a file; nil uses os.Chown.
	Chown func(path string, uid, gid int) error
	// EnsurePath creates folders inside a home without following symlinks,
	// like sysuser.System.EnsurePath.
	EnsurePath func(ctx context.Context, path sysuser.Path) error
	// Remove deletes paths inside an account's home for good, as the
	// account (files.OpRemove).
	Remove func(ctx context.Context, account files.Account, paths []string) error
	// Write replaces a file inside an account's home, as the account
	// (files.OpWrite).
	Write func(ctx context.Context, account files.Account, path, content string) error
}

// DefaultConfig returns the locations setup.sh configures Rspamd with;
// Remove still has to be set.
func DefaultConfig() Config {
	return Config{
		DKIMDir:        "/var/lib/rspamd/dkim",
		SelectorMap:    "/etc/rspamd/rayxo/dkim_selectors.map",
		SendLimitMap:   "/etc/rspamd/rayxo/send_limits.map",
		MailnamePath:   "/etc/mailname",
		SNIDir:         "/etc/rayxo/ssl/mail",
		DovecotSNIFile: "/etc/dovecot/rayxo-sni.conf",
		PostfixSNIMap:  "/etc/postfix/rayxo/sni",
		KeyGroup:       "_rspamd",
		EnsurePath:     sysuser.System{}.EnsurePath,
	}
}

// RemoveWith returns a Remove that runs files.OpRemove in helper.
func RemoveWith(helper files.Runner) func(context.Context, files.Account, []string) error {
	return func(ctx context.Context, account files.Account, paths []string) error {
		var outcome files.Outcome
		if err := helper.Run(ctx, account, files.OpRemove, files.PathsParams{Paths: paths}, nil, &outcome); err != nil {
			return err
		}

		if len(outcome.Failed) > 0 {
			return fmt.Errorf("%s: %s", outcome.Failed[0].Path, outcome.Failed[0].Message)
		}

		return nil
	}
}

// WriteWith returns a Write that runs files.OpWrite in helper.
func WriteWith(helper files.Runner) func(context.Context, files.Account, string, string) error {
	return func(ctx context.Context, account files.Account, path, content string) error {
		var written files.Content

		return helper.Run(ctx, account, files.OpWrite, files.WriteParams{Path: path, Content: content}, nil, &written)
	}
}

// Manager applies mail changes. Callers make sure only one call changes a
// given account at a time (the agent's account lock).
type Manager struct {
	cfg Config
}

// New returns a Manager for cfg.
func New(cfg Config) *Manager {
	if cfg.LookupGroup == nil {
		cfg.LookupGroup = lookupGroup
	}

	if cfg.Chown == nil {
		cfg.Chown = os.Chown
	}

	if cfg.Run == nil {
		cfg.Run = runner.Run
	}

	return &Manager{cfg: cfg}
}

// ApplyDomain makes the folder of domain's mailboxes in the home of
// account, and the DKIM key Rspamd signs the domain's mail with under
// selector, when they are missing. It returns the public key for the
// domain's DKIM record (base64 DER). Calling it again changes nothing.
func (m *Manager) ApplyDomain(ctx context.Context, account sysuser.User, domain, selector string) (string, error) {
	if err := validate.Domain(domain); err != nil {
		return "", err
	}

	if err := ValidateSelector(selector); err != nil {
		return "", err
	}

	err := m.cfg.EnsurePath(ctx, sysuser.Path{
		Home: account.Home, Dirs: []string{imapDir, domain}, UID: account.UID, GID: account.GID, Mode: 0o700,
	})
	if err != nil {
		return "", fmt.Errorf("mail folder of %s: %w", domain, err)
	}

	key, err := m.ensureKey(domain, selector)
	if err != nil {
		return "", err
	}

	public, err := x509.MarshalPKIXPublicKey(&key.PublicKey)
	if err != nil {
		return "", err
	}

	if err := m.setSelector(domain, selector); err != nil {
		return "", err
	}

	return base64.StdEncoding.EncodeToString(public), nil
}

// RemoveDomain stops signing domain's mail and deletes its DKIM keys, and,
// when account is not nil, the mail of all its mailboxes. Nothing missing
// is an error.
func (m *Manager) RemoveDomain(ctx context.Context, account *sysuser.User, domain string) error {
	if err := validate.Domain(domain); err != nil {
		return err
	}

	if err := m.setSelector(domain, ""); err != nil {
		return err
	}

	entries, err := os.ReadDir(m.cfg.DKIMDir)
	if err != nil && !errors.Is(err, os.ErrNotExist) {
		return err
	}

	for _, entry := range entries {
		name, ok := strings.CutSuffix(entry.Name(), ".key")
		if !ok {
			continue
		}

		dot := strings.LastIndex(name, ".")
		if dot < 0 || name[:dot] != domain {
			continue
		}

		if err := os.Remove(filepath.Join(m.cfg.DKIMDir, entry.Name())); err != nil && !errors.Is(err, os.ErrNotExist) {
			return err
		}
	}

	if account == nil {
		return nil
	}

	return m.cfg.Remove(ctx, files.Account{UID: account.UID, GID: account.GID, Home: account.Home}, []string{imapDir + "/" + domain})
}

// RemoveMailbox deletes the mail of localPart@domain from the home of
// account. A mailbox that never got mail has nothing to delete.
func (m *Manager) RemoveMailbox(ctx context.Context, account sysuser.User, domain, localPart string) error {
	if err := validate.Domain(domain); err != nil {
		return err
	}

	if err := ValidateLocalPart(localPart); err != nil {
		return err
	}

	return m.cfg.Remove(ctx, files.Account{UID: account.UID, GID: account.GID, Home: account.Home}, []string{imapDir + "/" + domain + "/" + localPart})
}

// ApplySieve makes script the Sieve script of localPart@domain, in the
// mailbox's folder (made when missing), as account; an empty script
// removes it and its compiled form, so only the server's own script runs.
func (m *Manager) ApplySieve(ctx context.Context, account sysuser.User, domain, localPart, script string) error {
	if err := validate.Domain(domain); err != nil {
		return err
	}

	if err := ValidateLocalPart(localPart); err != nil {
		return err
	}

	if err := ValidateSieve(script); err != nil {
		return err
	}

	owner := files.Account{UID: account.UID, GID: account.GID, Home: account.Home}
	folder := imapDir + "/" + domain + "/" + localPart

	if script == "" {
		return m.cfg.Remove(ctx, owner, []string{folder + "/" + SieveFile, folder + "/" + SieveBinary})
	}

	err := m.cfg.EnsurePath(ctx, sysuser.Path{
		Home: account.Home, Dirs: []string{imapDir, domain, localPart}, UID: account.UID, GID: account.GID, Mode: 0o700,
	})
	if err != nil {
		return fmt.Errorf("folder of %s@%s: %w", localPart, domain, err)
	}

	return m.cfg.Write(ctx, owner, folder+"/"+SieveFile, script)
}

// ensureKey returns the DKIM key of domain and selector, made when there
// is none yet.
func (m *Manager) ensureKey(domain, selector string) (*rsa.PrivateKey, error) {
	path := filepath.Join(m.cfg.DKIMDir, domain+"."+selector+".key")

	content, err := os.ReadFile(path)
	if err == nil {
		return parseKey(content)
	}

	if !errors.Is(err, os.ErrNotExist) {
		return nil, err
	}

	key, err := rsa.GenerateKey(rand.Reader, keyBits)
	if err != nil {
		return nil, err
	}

	gid, err := m.cfg.LookupGroup(m.cfg.KeyGroup)
	if err != nil {
		return nil, fmt.Errorf("group %s: %w", m.cfg.KeyGroup, err)
	}

	encoded := pem.EncodeToMemory(&pem.Block{Type: "RSA PRIVATE KEY", Bytes: x509.MarshalPKCS1PrivateKey(key)})

	if err := m.writeFile(path, encoded, 0o640, gid); err != nil {
		return nil, err
	}

	return key, nil
}

// setSelector makes the selector map say that domain is signed with
// selector, or not signed when selector is empty.
func (m *Manager) setSelector(domain, selector string) error {
	content, err := os.ReadFile(m.cfg.SelectorMap)
	if err != nil && !errors.Is(err, os.ErrNotExist) {
		return err
	}

	var lines []string

	for line := range strings.Lines(string(content)) {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") || strings.Fields(line)[0] == domain {
			continue
		}

		lines = append(lines, line)
	}

	if selector != "" {
		lines = append(lines, domain+" "+selector)
	}

	slices.Sort(lines)

	text := "# Written by rayxod: the DKIM selector of each mail domain.\n"
	if len(lines) > 0 {
		text += strings.Join(lines, "\n") + "\n"
	}

	if string(content) == text {
		return nil
	}

	return m.writeFile(m.cfg.SelectorMap, []byte(text), 0o644, -1)
}

// writeFile replaces path with content atomically; gid, when not -1, is
// the file's group.
func (m *Manager) writeFile(path string, content []byte, mode os.FileMode, gid int) error {
	temporary, err := os.CreateTemp(filepath.Dir(path), "."+filepath.Base(path)+".*")
	if err != nil {
		return err
	}

	name := temporary.Name()
	defer os.Remove(name)

	if _, err := temporary.Write(content); err != nil {
		temporary.Close()

		return err
	}

	if err := temporary.Close(); err != nil {
		return err
	}

	if err := os.Chmod(name, mode); err != nil {
		return err
	}

	if gid >= 0 {
		if err := m.cfg.Chown(name, 0, gid); err != nil {
			return err
		}
	}

	return os.Rename(name, path)
}

func parseKey(content []byte) (*rsa.PrivateKey, error) {
	block, _ := pem.Decode(content)
	if block == nil {
		return nil, errors.New("the DKIM key is not PEM")
	}

	if key, err := x509.ParsePKCS1PrivateKey(block.Bytes); err == nil {
		return key, nil
	}

	parsed, err := x509.ParsePKCS8PrivateKey(block.Bytes)
	if err != nil {
		return nil, fmt.Errorf("the DKIM key is unreadable: %w", err)
	}

	key, ok := parsed.(*rsa.PrivateKey)
	if !ok {
		return nil, errors.New("the DKIM key is not RSA")
	}

	return key, nil
}

func lookupGroup(name string) (int, error) {
	group, err := user.LookupGroup(name)
	if err != nil {
		return 0, err
	}

	return strconv.Atoi(group.Gid)
}
