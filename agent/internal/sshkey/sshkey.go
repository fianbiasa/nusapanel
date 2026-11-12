// Package sshkey checks the OpenSSH public keys accounts add in the panel
// before they go into authorized_keys: one key per line, without options
// (a key added in the panel cannot run a forced command or open forwarding),
// of a type sshd accepts by default, and an RSA key of at least 2048 bits.
package sshkey

import (
	"bytes"
	"encoding/base64"
	"encoding/binary"
	"errors"
	"fmt"
	"math/big"
	"strings"
	"unicode"
	"unicode/utf8"
)

// Limits of a key line.
const (
	MaxLineBytes    = 16 << 10
	MaxCommentChars = 200
	MinRSABits      = 2048
	maxRSABits      = 16384
)

// Key is a checked public key.
type Key struct {
	Type    string
	Blob    []byte
	Comment string
}

// String is the key as a line of authorized_keys.
func (k Key) String() string {
	line := k.Type + " " + base64.StdEncoding.EncodeToString(k.Blob)
	if k.Comment != "" {
		line += " " + k.Comment
	}

	return line
}

// The key types sshd accepts by default, with the checks of their public
// part.
var keyTypes = map[string]func(r *reader) error{
	"ssh-ed25519":                        ed25519Key,
	"sk-ssh-ed25519@openssh.com":         securityKey(ed25519Key),
	"ecdsa-sha2-nistp256":                ecdsaKey("nistp256", 65),
	"ecdsa-sha2-nistp384":                ecdsaKey("nistp384", 97),
	"ecdsa-sha2-nistp521":                ecdsaKey("nistp521", 133),
	"sk-ecdsa-sha2-nistp256@openssh.com": securityKey(ecdsaKey("nistp256", 65)),
	"ssh-rsa":                            rsaKey,
}

// Parse checks one line in the form "type base64 comment".
func Parse(line string) (Key, error) {
	if len(line) > MaxLineBytes {
		return Key{}, fmt.Errorf("a key may have at most %d KB", MaxLineBytes>>10)
	}

	if !utf8.ValidString(line) || strings.ContainsAny(line, "\r\n\x00") {
		return Key{}, errors.New("a key is one line of text")
	}

	fields := strings.Fields(line)
	if len(fields) < 2 {
		return Key{}, errors.New("a key is its type, the key itself and an optional comment")
	}

	check, ok := keyTypes[fields[0]]
	if !ok {
		return Key{}, fmt.Errorf("%q is not a key type this server accepts (ssh-ed25519, ecdsa-sha2-*, ssh-rsa or a security key), or the line starts with options", truncate(fields[0], 40))
	}

	blob, err := base64.StdEncoding.DecodeString(fields[1])
	if err != nil {
		return Key{}, errors.New("the key is not valid base64")
	}

	r := &reader{data: blob}
	if inner, err := r.str(); err != nil || string(inner) != fields[0] {
		return Key{}, fmt.Errorf("the key is not a %s key", fields[0])
	}

	if err := check(r); err != nil {
		return Key{}, err
	}

	if len(r.data) != 0 {
		return Key{}, errors.New("the key has data after its end")
	}

	comment := strings.Join(fields[2:], " ")
	if utf8.RuneCountInString(comment) > MaxCommentChars {
		return Key{}, fmt.Errorf("the comment may have at most %d characters", MaxCommentChars)
	}

	if strings.IndexFunc(comment, unicode.IsControl) >= 0 {
		return Key{}, errors.New("the comment has control characters")
	}

	return Key{Type: fields[0], Blob: blob, Comment: comment}, nil
}

func ed25519Key(r *reader) error {
	public, err := r.str()
	if err != nil || len(public) != 32 {
		return errors.New("the ed25519 key is damaged")
	}

	return nil
}

func ecdsaKey(curve string, pointSize int) func(r *reader) error {
	return func(r *reader) error {
		name, err := r.str()
		if err != nil || string(name) != curve {
			return fmt.Errorf("the key is not on curve %s", curve)
		}

		point, err := r.str()
		if err != nil || len(point) != pointSize || point[0] != 4 {
			return errors.New("the ecdsa key is damaged")
		}

		return nil
	}
}

// securityKey is a FIDO key: the key, then the application it is for.
func securityKey(key func(r *reader) error) func(r *reader) error {
	return func(r *reader) error {
		if err := key(r); err != nil {
			return err
		}

		application, err := r.str()
		if err != nil || !bytes.HasPrefix(application, []byte("ssh:")) {
			return errors.New("the security key has no ssh: application")
		}

		return nil
	}
}

func rsaKey(r *reader) error {
	exponent, err := r.str()
	if err != nil || len(exponent) == 0 || len(exponent) > 8 {
		return errors.New("the rsa key is damaged")
	}

	modulus, err := r.str()
	if err != nil || len(modulus) == 0 || modulus[0]&0x80 != 0 {
		return errors.New("the rsa key is damaged")
	}

	bits := new(big.Int).SetBytes(modulus).BitLen()
	if bits < MinRSABits || bits > maxRSABits {
		return fmt.Errorf("the rsa key has %d bits; it needs at least %d", bits, MinRSABits)
	}

	return nil
}

// reader reads the strings of the SSH wire format: a 4-byte length, then
// that many bytes.
type reader struct {
	data []byte
}

func (r *reader) str() ([]byte, error) {
	if len(r.data) < 4 {
		return nil, errors.New("short key")
	}

	size := binary.BigEndian.Uint32(r.data)
	if uint64(size) > uint64(len(r.data)-4) {
		return nil, errors.New("short key")
	}

	value := r.data[4 : 4+size]
	r.data = r.data[4+size:]

	return value, nil
}

func truncate(value string, size int) string {
	if utf8.RuneCountInString(value) <= size {
		return value
	}

	return string([]rune(value)[:size]) + "…"
}
