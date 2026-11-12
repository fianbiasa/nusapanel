// Package srs rewrites the envelope sender of mail this server forwards,
// with the Sender Rewriting Scheme, so the mail passes SPF where it goes:
// someone@gmail.com becomes SRS0=<hash>=<day>=gmail.com=someone@<server>,
// which this server may send for, and a bounce to that address comes back
// here and goes on to someone@gmail.com. The hash (an HMAC of the address
// and day under a secret key, 40 bits) makes sure only addresses this server
// made are turned back, and SRS0 ones only for 21 days, so they cannot be
// used to relay mail anywhere.
//
// Postfix asks through a socketmap (see Serve): "forward <address>" for
// envelope senders and "reverse <address>" for envelope recipients.
package srs

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base32"
	"encoding/binary"
	"errors"
	"strings"
	"time"
)

const (
	// hashLength is how many base32 characters of the HMAC an address
	// carries: 40 bits, far more than anyone can guess by trying addresses
	// on port 25. Base32 has one case, so mail servers that lowercase the
	// address on its way back do not weaken it.
	hashLength = 8
	// MaxAgeDays is how long a rewritten address is turned back.
	MaxAgeDays = 21
	// MinSecretLength is the shortest secret key accepted, in bytes.
	MinSecretLength = 16
	// stampAlphabet encodes the day in two characters (base32).
	stampAlphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567"
	// stampModulus is how many days the two characters can tell apart.
	stampModulus = 1 << 10
)

// Rewriter makes and checks SRS addresses in one domain.
type Rewriter struct {
	secret []byte
	domain string
	now    func() time.Time
}

// New returns a Rewriter for addresses in domain, signed with secret.
func New(secret []byte, domain string) (*Rewriter, error) {
	if len(secret) < MinSecretLength {
		return nil, errors.New("the SRS secret is too short")
	}

	domain = strings.ToLower(strings.TrimSuffix(strings.TrimSpace(domain), "."))
	if domain == "" || strings.ContainsAny(domain, "@ \t\r\n") {
		return nil, errors.New("the SRS domain is not a domain name")
	}

	return &Rewriter{secret: secret, domain: domain, now: time.Now}, nil
}

// Forward returns the address to send mail from address as, and false
// when it stays as it is: it is already in this server's domain, or it is
// not a full address. An address another forwarder rewrote (SRS0) becomes
// an SRS1 address that keeps that forwarder's, so a bounce goes back
// through it; an SRS1 address keeps its original forwarder.
func (r *Rewriter) Forward(address string) (string, bool) {
	local, domain, ok := split(address)
	if !ok || strings.EqualFold(domain, r.domain) {
		return "", false
	}

	switch {
	case hasTag(local, "SRS0"):
		// SRS0=<hash>=<day>=<domain>=<local>@forwarder: keep "=<hash>=..."
		// as it is and point back at the forwarder.
		user := local[len("SRS0"):]

		return "SRS1=" + r.hash(domain, user) + "=" + domain + "=" + user + "@" + r.domain, true
	case hasTag(local, "SRS1"):
		// SRS1=<hash>=<first forwarder>=<rest>: sign it again for here.
		parts := strings.SplitN(local[len("SRS1")+1:], "=", 3)
		if len(parts) == 3 && parts[1] != "" {
			return "SRS1=" + r.hash(parts[1], parts[2]) + "=" + parts[1] + "=" + parts[2] + "@" + r.domain, true
		}
	}

	stamp := r.stamp()

	return "SRS0=" + r.hash(stamp, domain, local) + "=" + stamp + "=" + domain + "=" + local + "@" + r.domain, true
}

// Reverse returns the address an SRS address of this server's domain was
// made from, and false for any other address, or one whose hash is wrong
// or that is too old.
func (r *Rewriter) Reverse(address string) (string, bool) {
	local, domain, ok := split(address)
	if !ok || !strings.EqualFold(domain, r.domain) {
		return "", false
	}

	switch {
	case hasTag(local, "SRS0"):
		parts := strings.SplitN(local[len("SRS0")+1:], "=", 4)
		if len(parts) != 4 || parts[2] == "" || parts[3] == "" {
			return "", false
		}

		hash, stamp, originalDomain, originalLocal := parts[0], parts[1], parts[2], parts[3]
		if !r.validStamp(stamp) || !r.validHash(hash, stamp, originalDomain, originalLocal) {
			return "", false
		}

		return originalLocal + "@" + originalDomain, true
	case hasTag(local, "SRS1"):
		parts := strings.SplitN(local[len("SRS1")+1:], "=", 3)
		if len(parts) != 3 || parts[1] == "" || parts[2] == "" {
			return "", false
		}

		hash, forwarder, user := parts[0], parts[1], parts[2]
		if !r.validHash(hash, forwarder, user) {
			return "", false
		}

		return "SRS0" + user + "@" + forwarder, true
	}

	return "", false
}

// hash is the first characters of the base32 HMAC-SHA256 of the lowercased
// parts. Each part goes in with its length first, so moving characters from
// one part to the next (a.co and mx, a.com and x) changes the hash.
func (r *Rewriter) hash(parts ...string) string {
	mac := hmac.New(sha256.New, r.secret)
	for _, part := range parts {
		part = strings.ToLower(part)

		var length [4]byte
		binary.BigEndian.PutUint32(length[:], uint32(len(part)))
		mac.Write(length[:])
		mac.Write([]byte(part))
	}

	return base32.StdEncoding.EncodeToString(mac.Sum(nil))[:hashLength]
}

// validHash compares without regard to case: mail servers may lowercase
// the address on its way back.
func (r *Rewriter) validHash(hash string, parts ...string) bool {
	if len(hash) != hashLength {
		return false
	}

	return hmac.Equal([]byte(strings.ToUpper(hash)), []byte(r.hash(parts...)))
}

// stamp is today, in days since 1970, as two base32 characters.
func (r *Rewriter) stamp() string {
	day := r.today()

	return string([]byte{stampAlphabet[(day>>5)&31], stampAlphabet[day&31]})
}

func (r *Rewriter) validStamp(stamp string) bool {
	if len(stamp) != 2 {
		return false
	}

	high := strings.IndexByte(stampAlphabet, upper(stamp[0]))
	low := strings.IndexByte(stampAlphabet, upper(stamp[1]))
	if high < 0 || low < 0 {
		return false
	}

	age := (r.today() - (high<<5 | low) + stampModulus) % stampModulus

	return age <= MaxAgeDays
}

func (r *Rewriter) today() int {
	return int(r.now().Unix()/86400) % stampModulus
}

// split parts a full address at its last "@".
func split(address string) (local, domain string, ok bool) {
	at := strings.LastIndexByte(address, '@')
	if at <= 0 || at == len(address)-1 {
		return "", "", false
	}

	return address[:at], address[at+1:], true
}

// hasTag reports whether local starts with tag (any case) and a separator.
func hasTag(local, tag string) bool {
	return len(local) > len(tag) && strings.EqualFold(local[:len(tag)], tag) && strings.IndexByte("=+-", local[len(tag)]) >= 0
}

func upper(c byte) byte {
	if c >= 'a' && c <= 'z' {
		return c - 'a' + 'A'
	}

	return c
}
