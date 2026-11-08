package webserver

import (
	"errors"
	"fmt"
	"net"
	"net/url"
	"regexp"
	"slices"
	"strings"

	"nusapanel/agent/internal/validate"
)

// Kind is what a site serves.
type Kind string

const (
	// KindDomain serves the site's own folder, domains/<domain>/public_html.
	// Domains and subdomains are both of this kind.
	KindDomain Kind = "domain"
	// KindAlias serves the folder of the site's Parent: a domain pointer
	// that shows the same website.
	KindAlias Kind = "alias"
	// KindForward redirects every request to the site's Parent with a 301:
	// a domain pointer that sends visitors to the main domain.
	KindForward Kind = "forward"
)

// MaxRedirects is the most URL redirects one site may have.
const MaxRedirects = 100

// Redirect sends visitors of one path of a site to another URL.
type Redirect struct {
	// Path is "/" or a path like /promo/2026: segments of letters, digits
	// and ._~- separated by single slashes, without a trailing slash.
	Path string
	// Destination is an absolute http:// or https:// URL.
	Destination string
	// Status is 301 (permanent) or 302 (temporary).
	Status int
	// Wildcard also redirects everything under Path, keeping the rest of
	// the path: /promo/a goes to <Destination>/a. Its Destination has no
	// query or fragment.
	Wildcard bool
}

var (
	redirectSegmentPattern = regexp.MustCompile(`^[A-Za-z0-9._~-]+$`)
	// Characters a destination may contain: those of RFC 3986 except
	// $ ; ' and everything else that means something in an Nginx string.
	destinationPattern = regexp.MustCompile(`^[A-Za-z0-9._~:/?#@!&()*+,=%\[\]-]+$`)
)

// validateRedirectPath returns an error unless path is a valid Redirect.Path.
func validateRedirectPath(path string) error {
	if path == "/" {
		return nil
	}

	if len(path) > 255 || !strings.HasPrefix(path, "/") || strings.HasSuffix(path, "/") {
		return fmt.Errorf("redirect path %q must start with / and not end with one, at most 255 characters", path)
	}

	for segment := range strings.SplitSeq(path[1:], "/") {
		if !redirectSegmentPattern.MatchString(segment) || segment == "." || segment == ".." {
			return fmt.Errorf("redirect path %q may only contain letters, digits and ._~- between single slashes", path)
		}
	}

	return nil
}

// validateRedirectDestination returns an error unless destination is a
// valid Redirect.Destination.
func validateRedirectDestination(destination string, wildcard bool) error {
	if len(destination) > 2048 || !destinationPattern.MatchString(destination) {
		return fmt.Errorf("redirect destination %q contains characters that are not allowed, or is longer than 2048", destination)
	}

	if !strings.HasPrefix(destination, "http://") && !strings.HasPrefix(destination, "https://") {
		return fmt.Errorf("redirect destination %q must start with http:// or https://", destination)
	}

	parsed, err := url.Parse(destination)
	if err != nil || parsed.User != nil || parsed.Opaque != "" {
		return fmt.Errorf("redirect destination %q is not a valid URL", destination)
	}

	host := parsed.Hostname()
	if validate.Domain(host) != nil && net.ParseIP(host) == nil {
		return fmt.Errorf("redirect destination %q needs a valid host name", destination)
	}

	if wildcard && strings.ContainsAny(destination, "?#") {
		return fmt.Errorf("redirect destination %q of a wildcard redirect must not have a query or fragment", destination)
	}

	return nil
}

// validateRedirects returns an error unless every redirect is valid and no
// two share a path.
func validateRedirects(redirects []Redirect) error {
	if len(redirects) > MaxRedirects {
		return fmt.Errorf("a site may have at most %d redirects", MaxRedirects)
	}

	paths := make([]string, 0, len(redirects))

	for _, redirect := range redirects {
		if err := validateRedirectPath(redirect.Path); err != nil {
			return err
		}

		if err := validateRedirectDestination(redirect.Destination, redirect.Wildcard); err != nil {
			return err
		}

		if redirect.Status != 301 && redirect.Status != 302 {
			return fmt.Errorf("redirect status %d must be 301 or 302", redirect.Status)
		}

		if slices.Contains(paths, redirect.Path) {
			return fmt.Errorf("redirect path %q is used twice", redirect.Path)
		}

		paths = append(paths, redirect.Path)
	}

	return nil
}

// validateKind checks the kind of site and its parent.
func validateKind(site Site) error {
	switch site.Kind {
	case KindDomain:
		if site.Parent != "" {
			return errors.New("a domain has no parent")
		}
	case KindAlias, KindForward:
		if err := validate.Domain(site.Parent); err != nil {
			return fmt.Errorf("parent: %w", err)
		}

		if site.Parent == site.Domain {
			return errors.New("a pointer cannot point to itself")
		}
	default:
		return fmt.Errorf("unknown kind %q", site.Kind)
	}

	if site.Kind == KindForward && len(site.Redirects) > 0 {
		return errors.New("a forwarding pointer has no redirects of its own")
	}

	return nil
}

// redirectData is one redirect as the Nginx vhost template sees it.
type redirectData struct {
	Path     string
	Status   int
	Wildcard bool
	// Location is the regular expression of the location block, matched
	// against the decoded path.
	Location string
	// RequestPattern is matched against the raw $request_uri of a
	// wildcard redirect and captures the rest of the path, still encoded,
	// as $nusa_rest.
	RequestPattern string
	// Target is where the redirect goes; that of a wildcard redirect ends
	// in a slash, so the rest of the path can follow.
	Target string
	// Query is appended to Target: the visitor's query string, unless the
	// destination has a query or fragment of its own.
	Query string
}

// redirectsData returns the redirects in the order Nginx must see them:
// the first matching regular expression location wins, so longer paths
// come first and a wildcard on / never hides a more specific redirect.
func redirectsData(redirects []Redirect) []redirectData {
	sorted := slices.Clone(redirects)
	slices.SortStableFunc(sorted, func(a, b Redirect) int {
		if len(a.Path) != len(b.Path) {
			return len(b.Path) - len(a.Path)
		}

		return strings.Compare(a.Path, b.Path)
	})

	data := make([]redirectData, 0, len(sorted))

	for _, redirect := range sorted {
		// The path only has letters, digits and ._~-; of those, only the dot
		// means something in a regular expression. "/" becomes "".
		base := strings.ReplaceAll(strings.TrimSuffix(redirect.Path, "/"), ".", `\.`)

		item := redirectData{
			Path:     redirect.Path,
			Status:   redirect.Status,
			Wildcard: redirect.Wildcard,
			Location: "^" + base + "/?$",
			Target:   redirect.Destination,
		}

		if !strings.ContainsAny(redirect.Destination, "?#") {
			item.Query = "$is_args$args"
		}

		if redirect.Wildcard {
			item.Location = "^" + base + "(?:/.*)?$"
			item.RequestPattern = "^" + base + "(?:/(?<nusa_rest>[^?]*))?"
			item.Target = strings.TrimSuffix(redirect.Destination, "/") + "/"
		}

		data = append(data, item)
	}

	return data
}
