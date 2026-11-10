package webserver

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/tls"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/netip"
	"syscall"
	"time"

	"rayxo/agent/internal/validate"
)

// Before certbot asks Let's Encrypt for a certificate, the agent checks the
// way Let's Encrypt will that a name reaches this server: it puts a random
// file into the ACME webroot and fetches it back over HTTP. A name that
// fails gets a readable reason right away instead of a failed validation,
// which also counts against Let's Encrypt's rate limits.

const (
	// reachTimeout bounds one check, redirects included.
	reachTimeout = 10 * time.Second
	// maxRedirects is how many redirects a check follows.
	maxRedirects = 5
	// maxTokenResponse is how much of a response is read; the token is far
	// shorter.
	maxTokenResponse = 4096
)

var errRedirectRefused = errors.New("redirect Let's Encrypt would not follow")

// nonPublicPrefixes are addresses that are not reachable from the internet
// beyond what netip.Addr's own checks cover.
var nonPublicPrefixes = []netip.Prefix{
	netip.MustParsePrefix("0.0.0.0/8"),
	netip.MustParsePrefix("100.64.0.0/10"),
	netip.MustParsePrefix("198.18.0.0/15"),
	netip.MustParsePrefix("240.0.0.0/4"),
}

// nonPublicAddressError means a name resolved to an address Let's Encrypt
// cannot reach, such as 127.0.0.1 or 10.0.0.5.
type nonPublicAddressError struct {
	addr netip.Addr
}

func (e *nonPublicAddressError) Error() string {
	return "refusing to connect to non-public address " + e.addr.String()
}

// unexpectedStatusError means the token was answered with another status
// than 200.
type unexpectedStatusError struct {
	status int
}

func (e *unexpectedStatusError) Error() string {
	return fmt.Sprintf("HTTP status %d", e.status)
}

// reachableNames returns the names to request a certificate for: domain,
// which must reach this server (else *UnreachableError), and www.<domain>
// when it reaches this server too.
func (m *Manager) reachableNames(ctx context.Context, domain string) ([]string, error) {
	if err := m.checkReaches(ctx, domain); err != nil {
		return nil, err
	}

	names := []string{domain}

	www := "www." + domain
	if validate.Domain(www) != nil {
		return names, nil
	}

	err := m.checkReaches(ctx, www)
	if unreachable, ok := errors.AsType[*UnreachableError](err); ok {
		m.logger().Info("certificate requested without www", "domain", domain, "reason", unreachable.Reason)

		return names, nil
	}

	if err != nil {
		return nil, err
	}

	return append(names, www), nil
}

// checkReaches serves a random token from the ACME webroot and fetches it
// from name over HTTP, the way Let's Encrypt does. It returns
// *UnreachableError when the token does not come back unchanged.
func (m *Manager) checkReaches(ctx context.Context, name string) error {
	dir := m.challengeDir()
	// Created once and kept: certbot only removes challenge folders it
	// created itself, so it never removes this one while a check uses it.
	if err := ensureDir(dir, 0o755); err != nil {
		return err
	}

	file := "rayxo-" + rand.Text()
	token := []byte(rand.Text())
	path := dir + "/" + file

	if err := writeFileAtomic(path, token, 0o644, nil); err != nil {
		return err
	}

	defer func() {
		if err := removeFile(path); err != nil {
			m.logger().Warn("could not remove a reachability token", "error", err)
		}
	}()

	answer, err := m.fetchToken(ctx, "http://"+name+"/.well-known/acme-challenge/"+file)

	reason := ""

	switch {
	case err != nil:
		reason = unreachableReason(err)
	case !bytes.Equal(answer, token):
		reason = "it answers with another site"
	default:
		return nil
	}

	m.logger().Info("name does not reach this server over HTTP", "name", name, "reason", reason, "error", err)

	return &UnreachableError{Domain: name, Reason: reason}
}

// fetchToken returns the body of a 200 answer to a GET of url.
func (m *Manager) fetchToken(ctx context.Context, url string) ([]byte, error) {
	ctx, cancel := context.WithTimeout(ctx, reachTimeout)
	defer cancel()

	request, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, err
	}

	request.Header.Set("User-Agent", "rayxo-agent reachability check")

	response, err := m.reachClient().Do(request)
	if err != nil {
		return nil, err
	}

	defer response.Body.Close()

	if response.StatusCode != http.StatusOK {
		return nil, &unexpectedStatusError{status: response.StatusCode}
	}

	return io.ReadAll(io.LimitReader(response.Body, maxTokenResponse))
}

// reachClient returns an HTTP client that behaves like Let's Encrypt's
// validation: it follows a few redirects, only to http or https on the
// standard ports, and does not check HTTPS certificates (Let's Encrypt does
// not either when a redirect leads to HTTPS; only the token matters).
func (m *Manager) reachClient() *http.Client {
	dial := m.cfg.DialHTTP
	if dial == nil {
		dial = dialPublic
	}

	return &http.Client{
		Transport: &http.Transport{
			Proxy:                  nil,
			DialContext:            dial,
			TLSClientConfig:        &tls.Config{InsecureSkipVerify: true}, //nolint:gosec // see above
			TLSHandshakeTimeout:    reachTimeout,
			ResponseHeaderTimeout:  reachTimeout,
			DisableKeepAlives:      true,
			MaxResponseHeaderBytes: 64 << 10,
		},
		CheckRedirect: func(request *http.Request, via []*http.Request) error {
			if len(via) > maxRedirects {
				return fmt.Errorf("%w: more than %d redirects", errRedirectRefused, maxRedirects)
			}

			scheme, port := request.URL.Scheme, request.URL.Port()
			if (scheme != "http" && scheme != "https") || (port != "" && port != "80" && port != "443") {
				return fmt.Errorf("%w: %s", errRedirectRefused, request.URL.Redacted())
			}

			return nil
		},
	}
}

// dialPublic connects only to public addresses. Let's Encrypt never
// validates through any other, and the check must not let a customer's DNS
// record point the agent at this server's own network.
func dialPublic(ctx context.Context, network, address string) (net.Conn, error) {
	dialer := net.Dialer{Timeout: reachTimeout, Control: refuseNonPublic}

	return dialer.DialContext(ctx, network, address)
}

// refuseNonPublic is a net.Dialer Control function that refuses to connect
// to an address that is not public.
func refuseNonPublic(_, address string, _ syscall.RawConn) error {
	addrPort, err := netip.ParseAddrPort(address)
	if err != nil {
		return fmt.Errorf("parse dial address %q: %w", address, err)
	}

	if !isPublicAddress(addrPort.Addr()) {
		return &nonPublicAddressError{addr: addrPort.Addr()}
	}

	return nil
}

// isPublicAddress reports whether addr is a unicast address on the internet.
func isPublicAddress(addr netip.Addr) bool {
	addr = addr.Unmap()

	if !addr.IsGlobalUnicast() || addr.IsPrivate() {
		return false
	}

	for _, prefix := range nonPublicPrefixes {
		if prefix.Contains(addr) {
			return false
		}
	}

	return true
}

// unreachableReason says in a few words why a check failed with err.
func unreachableReason(err error) string {
	var dnsErr *net.DNSError

	switch {
	case errors.As(err, new(*nonPublicAddressError)):
		return "its DNS record points to a private address"
	case errors.As(err, &dnsErr) && dnsErr.IsNotFound:
		return "no DNS record"
	case errors.As(err, &dnsErr) && dnsErr.IsTimeout:
		return "its DNS lookup timed out"
	case errors.As(err, &dnsErr):
		return "its DNS lookup failed"
	case errors.Is(err, errRedirectRefused):
		return "it redirects somewhere Let's Encrypt does not follow"
	case errors.As(err, new(*unexpectedStatusError)):
		return "it answers with another site"
	case isTimeout(err):
		return "timed out"
	case errors.Is(err, syscall.ECONNREFUSED):
		return "it refuses connections on port 80"
	}

	return "the connection failed"
}

// isTimeout reports whether err comes from a deadline.
func isTimeout(err error) bool {
	if errors.Is(err, context.DeadlineExceeded) {
		return true
	}

	netErr, ok := errors.AsType[net.Error](err)

	return ok && netErr.Timeout()
}
