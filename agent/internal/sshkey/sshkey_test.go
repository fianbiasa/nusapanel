package sshkey

import (
	"crypto/ecdsa"
	"crypto/ed25519"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/rsa"
	"encoding/base64"
	"encoding/binary"
	"math/big"
	"strings"
	"testing"
)

func wire(parts ...[]byte) []byte {
	var out []byte
	for _, part := range parts {
		out = binary.BigEndian.AppendUint32(out, uint32(len(part)))
		out = append(out, part...)
	}

	return out
}

func line(keyType string, blob []byte, comment string) string {
	return strings.TrimSpace(keyType + " " + base64.StdEncoding.EncodeToString(blob) + " " + comment)
}

func mpint(n *big.Int) []byte {
	b := n.Bytes()
	if b[0]&0x80 != 0 {
		b = append([]byte{0}, b...)
	}

	return b
}

func rsaLine(t *testing.T, bits int) string {
	t.Helper()

	key, err := rsa.GenerateKey(rand.Reader, bits)
	if err != nil {
		t.Fatal(err)
	}

	return line("ssh-rsa", wire([]byte("ssh-rsa"), mpint(big.NewInt(int64(key.E))), mpint(key.N)), "")
}

func TestKeysSSHDAcceptsAreKept(t *testing.T) {
	public, _, _ := ed25519.GenerateKey(rand.Reader)
	ecdsaKey, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	point, _ := ecdsaKey.PublicKey.Bytes()

	for name, value := range map[string]string{
		"ed25519 with a comment": line("ssh-ed25519", wire([]byte("ssh-ed25519"), public), "budi@laptop  rumah"),
		"ecdsa":                  line("ecdsa-sha2-nistp256", wire([]byte("ecdsa-sha2-nistp256"), []byte("nistp256"), point), ""),
		"security key":           line("sk-ssh-ed25519@openssh.com", wire([]byte("sk-ssh-ed25519@openssh.com"), public, []byte("ssh:")), "yubikey"),
		"rsa 2048":               rsaLine(t, 2048),
	} {
		key, err := Parse("  " + value + "  ")
		if err != nil {
			t.Errorf("%s refused: %v", name, err)

			continue
		}

		if want := strings.Join(strings.Fields(value), " "); key.String() != want {
			t.Errorf("%s became %q, want %q", name, key.String(), want)
		}
	}
}

func TestDangerousOrWeakKeysAreRefused(t *testing.T) {
	public, _, _ := ed25519.GenerateKey(rand.Reader)
	good := line("ssh-ed25519", wire([]byte("ssh-ed25519"), public), "")

	for name, value := range map[string]string{
		"a forced command":       `command="/bin/sh -c id" ` + good,
		"forwarding options":     "no-pty,permitopen=\"*:*\" " + good,
		"a second key line":      good + "\n" + good,
		"dsa":                    line("ssh-dss", wire([]byte("ssh-dss")), ""),
		"rsa below 2048 bits":    rsaLine(t, 1024),
		"a type that lies":       line("ssh-ed25519", wire([]byte("ssh-rsa"), public), ""),
		"a short ed25519 key":    line("ssh-ed25519", wire([]byte("ssh-ed25519"), public[:16]), ""),
		"data after the key":     line("ssh-ed25519", append(wire([]byte("ssh-ed25519"), public), 1), ""),
		"not base64":             "ssh-ed25519 AAAA!!!!",
		"only a type":            "ssh-ed25519",
		"a control character":    good + " a\x1bb",
		"a length past the data": line("ssh-ed25519", []byte{0, 0, 0, 200, 's'}, ""),
	} {
		if _, err := Parse(value); err == nil {
			t.Errorf("%s was accepted", name)
		}
	}
}
