package webserver

import (
	"errors"
	"fmt"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strconv"
	"strings"

	"rayxo/agent/internal/validate"
)

// ErrorPageStatuses are the HTTP statuses a site may answer with a page of
// its own, <document root>/.errors/<status>.html. The 503 page also stands
// in for 502 and 504, when Apache or PHP cannot answer.
var ErrorPageStatuses = []int{400, 401, 403, 404, 500, 503}

// StaticExtensions are the files Nginx serves straight from disk. Hotlink
// protection covers some of them; they can never run as PHP, since Nginx
// would show their source instead.
var StaticExtensions = []string{
	"css", "js", "mjs", "map", "jpg", "jpeg", "png", "gif", "webp", "avif", "svg", "ico", "bmp",
	"woff", "woff2", "ttf", "otf", "eot", "mp4", "webm", "ogg", "mp3", "wav", "pdf", "txt",
	"zip", "gz", "tgz", "rar", "7z",
}

const (
	// MaxProtectedDirectories is the most protected directories one site
	// may have, and MaxProtectedUsers the most users one of them may have.
	MaxProtectedDirectories = 20
	MaxProtectedUsers       = 50
	// MaxMIMETypes is the most content types a site may override.
	MaxMIMETypes = 100
	// MaxPHPExtensions is the most extensions a site may run as PHP on top
	// of .php and .phtml.
	MaxPHPExtensions = 20
	// MaxHotlinkDomains is the most other websites hotlink protection may
	// allow.
	MaxHotlinkDomains = 50

	// errorPagesFolder holds a site's error pages, below its document root.
	errorPagesFolder = "/.errors"
	// The user files of protected directories are <domain>@<key>.htpasswd.
	// No domain has an @, so the files of example.com are never mistaken
	// for those of example.com.au.
	htpasswdSeparator = "@"
	htpasswdSuffix    = ".htpasswd"
)

// ProtectedDirectory asks for a username and password before anything in
// a folder of the website is served, by Nginx and by Apache alike.
type ProtectedDirectory struct {
	// Path is "/" for the whole website, or a folder below its document
	// root like /admin/files: names of letters, digits and ._- that do
	// not start with a dot, between single slashes.
	Path string
	// Realm is what the browser shows when it asks for the login.
	Realm string
	// Users may log in. A directory without users lets nobody in.
	Users []ProtectedUser
}

// ProtectedUser is one login of a protected directory.
type ProtectedUser struct {
	Name string
	// Hash is the password's SHA-512 crypt hash, $6$<salt>$<hash>, which
	// both Nginx and Apache check through the system's crypt().
	Hash string
}

// Hotlink keeps other websites from embedding the site's files: a request
// for one of Extensions whose Referer is another website is refused.
type Hotlink struct {
	// Extensions are the protected files, from StaticExtensions.
	Extensions []string
	// Domains may embed the files too, with their subdomains. The site
	// itself and its own subdomains always may.
	Domains []string
	// AllowDirect serves requests without a Referer: an address typed in,
	// a bookmark, an app.
	AllowDirect bool
	// RedirectTo, when set, sends refused requests to this http:// or
	// https:// URL of another website instead of answering 403.
	RedirectTo string
}

// MIMEType serves files whose name ends in .<Extension> as Type.
type MIMEType struct {
	// Extension is lowercase letters and digits, without the dot.
	Extension string
	// Type is a lowercase media type such as application/x-custom.
	Type string
}

var (
	protectedSegmentPattern = regexp.MustCompile(`^[A-Za-z0-9_-][A-Za-z0-9._-]{0,99}$`)
	// A realm is shown between double quotes by both servers, and Nginx
	// expands variables in it: no quote, backslash or $.
	realmPattern         = regexp.MustCompile(`^[A-Za-z0-9._,:()!?@&+/-](?:[A-Za-z0-9 ._,:()!?@&+/-]{0,62}[A-Za-z0-9._,:()!?@&+/-])?$`)
	protectedUserPattern = regexp.MustCompile(`^[A-Za-z0-9._-]{1,64}$`)
	sha512CryptPattern   = regexp.MustCompile(`^\$6\$(?:rounds=[0-9]{4,9}\$)?[./A-Za-z0-9]{1,16}\$[./A-Za-z0-9]{86}$`)
	extensionPattern     = regexp.MustCompile(`^[a-z0-9]{1,16}$`)
	mediaTypePattern     = regexp.MustCompile(`^[a-z0-9][a-z0-9._+-]{0,63}/[a-z0-9][a-z0-9._+-]{0,63}$`)
)

// alwaysPHP are the extensions every site with PHP runs as PHP.
var alwaysPHP = []string{"php", "phtml"}

// validateWebSettings checks the error pages, protected directories,
// hotlink protection, content types and PHP extensions of site. A forward
// serves no files, so it has none of them.
func validateWebSettings(site Site) error {
	if site.kind() == KindForward && (len(site.ErrorPages) > 0 || len(site.ProtectedDirectories) > 0 ||
		site.Hotlink != nil || len(site.MIMETypes) > 0 || len(site.PHPExtensions) > 0 || site.CGI || site.Suspended) {
		return errors.New("a forwarding pointer serves no files, so it has no web settings")
	}

	return errors.Join(
		validateErrorPages(site.ErrorPages),
		validateProtectedDirectories(site.ProtectedDirectories),
		validateHotlink(site.Domain, site.Hotlink),
		validateMIMETypes(site.MIMETypes),
		validatePHPExtensions(site.PHPExtensions),
	)
}

func validateErrorPages(statuses []int) error {
	for i, status := range statuses {
		if !slices.Contains(ErrorPageStatuses, status) {
			return fmt.Errorf("error page %d: only %v may have a page", status, ErrorPageStatuses)
		}

		if slices.Contains(statuses[:i], status) {
			return fmt.Errorf("error page %d is listed twice", status)
		}
	}

	return nil
}

// validateProtectedPath returns an error unless path is a valid
// ProtectedDirectory.Path.
func validateProtectedPath(path string) error {
	if path == "/" {
		return nil
	}

	if len(path) > 255 || !strings.HasPrefix(path, "/") || strings.HasSuffix(path, "/") {
		return fmt.Errorf("protected directory %q must start with / and not end with one, at most 255 characters", path)
	}

	for segment := range strings.SplitSeq(path[1:], "/") {
		if !protectedSegmentPattern.MatchString(segment) {
			return fmt.Errorf("protected directory %q may only have folder names of letters, digits and ._- that do not start with a dot", path)
		}
	}

	return nil
}

func validateProtectedDirectories(directories []ProtectedDirectory) error {
	if len(directories) > MaxProtectedDirectories {
		return fmt.Errorf("a site may have at most %d protected directories", MaxProtectedDirectories)
	}

	for i, directory := range directories {
		if err := validateProtectedPath(directory.Path); err != nil {
			return err
		}

		if slices.ContainsFunc(directories[:i], func(other ProtectedDirectory) bool { return other.Path == directory.Path }) {
			return fmt.Errorf("protected directory %s is listed twice", directory.Path)
		}

		if !realmPattern.MatchString(directory.Realm) {
			return fmt.Errorf("protected directory %s: the prompt must be 1 to 64 letters, digits, spaces or ._,:()!?@&+/-, without a space at either end", directory.Path)
		}

		if len(directory.Users) > MaxProtectedUsers {
			return fmt.Errorf("protected directory %s may have at most %d users", directory.Path, MaxProtectedUsers)
		}

		for j, user := range directory.Users {
			if !protectedUserPattern.MatchString(user.Name) {
				return fmt.Errorf("protected directory %s: user %q must be 1 to 64 letters, digits or ._-", directory.Path, user.Name)
			}

			if !sha512CryptPattern.MatchString(user.Hash) {
				return fmt.Errorf("protected directory %s: the password of %s is not a SHA-512 crypt hash", directory.Path, user.Name)
			}

			if slices.ContainsFunc(directory.Users[:j], func(other ProtectedUser) bool { return other.Name == user.Name }) {
				return fmt.Errorf("protected directory %s: user %s is listed twice", directory.Path, user.Name)
			}
		}
	}

	return nil
}

func validateHotlink(domain string, hotlink *Hotlink) error {
	if hotlink == nil {
		return nil
	}

	if len(hotlink.Extensions) == 0 {
		return errors.New("hotlink protection needs at least one file extension")
	}

	for i, extension := range hotlink.Extensions {
		if !isStaticExtension(extension) {
			return fmt.Errorf("hotlink protection: %q is not one of %v", extension, StaticExtensions)
		}

		if slices.Contains(hotlink.Extensions[:i], extension) {
			return fmt.Errorf("hotlink protection: %s is listed twice", extension)
		}
	}

	if len(hotlink.Domains) > MaxHotlinkDomains {
		return fmt.Errorf("hotlink protection may allow at most %d websites", MaxHotlinkDomains)
	}

	for _, allowed := range hotlink.Domains {
		if err := validate.Domain(allowed); err != nil {
			return fmt.Errorf("hotlink protection: %w", err)
		}
	}

	if hotlink.RedirectTo == "" {
		return nil
	}

	if err := validateRedirectDestination(hotlink.RedirectTo, false); err != nil {
		return fmt.Errorf("hotlink protection: %w", err)
	}

	// Browsers keep the Referer of the page that embedded the file, so a
	// redirect back into the site would be refused again, without end.
	parsed, err := url.Parse(hotlink.RedirectTo)
	if err != nil {
		return fmt.Errorf("hotlink protection: %w", err)
	}

	host := strings.ToLower(parsed.Hostname())
	if host == domain || strings.HasSuffix(host, "."+domain) {
		return errors.New("hotlink protection must redirect to another website, or it would refuse its own redirect")
	}

	return nil
}

func validateMIMETypes(types []MIMEType) error {
	if len(types) > MaxMIMETypes {
		return fmt.Errorf("a site may override at most %d content types", MaxMIMETypes)
	}

	for i, mime := range types {
		if !extensionPattern.MatchString(mime.Extension) {
			return fmt.Errorf("content type: extension %q must be 1 to 16 lowercase letters or digits", mime.Extension)
		}

		if !mediaTypePattern.MatchString(mime.Type) {
			return fmt.Errorf("content type of .%s: %q is not a media type like application/x-example", mime.Extension, mime.Type)
		}

		if slices.ContainsFunc(types[:i], func(other MIMEType) bool { return other.Extension == mime.Extension }) {
			return fmt.Errorf("content type of .%s is listed twice", mime.Extension)
		}
	}

	return nil
}

func validatePHPExtensions(extensions []string) error {
	if len(extensions) > MaxPHPExtensions {
		return fmt.Errorf("a site may run at most %d more extensions as PHP", MaxPHPExtensions)
	}

	for i, extension := range extensions {
		if !extensionPattern.MatchString(extension) {
			return fmt.Errorf("PHP extension %q must be 1 to 16 lowercase letters or digits", extension)
		}

		if isStaticExtension(extension) {
			return fmt.Errorf(".%s files are served from disk by Nginx, which would show their source, so they cannot run as PHP", extension)
		}

		if slices.Contains(alwaysPHP, extension) || slices.Contains(extensions[:i], extension) {
			return fmt.Errorf("PHP extension .%s is listed twice", extension)
		}
	}

	return nil
}

// isStaticExtension reports whether Nginx serves files with extension from
// disk.
func isStaticExtension(extension string) bool {
	return slices.Contains(StaticExtensions, extension)
}

// errorPageData is one error page of a site as the vhost templates see it.
type errorPageData struct {
	Status int
	// NginxStatuses are the statuses Nginx answers with the page.
	NginxStatuses string
	// URI is the page, /.errors/<status>.html.
	URI string
}

// protectedData is one protected directory as the vhost templates see it.
type protectedData struct {
	Path  string
	Realm string
	// Location is the regular expression of the Nginx location, matched
	// against the decoded path: the folder and everything in it.
	Location string
	// Directory is the folder on disk, for Apache.
	Directory string
	// File holds the users and their hashes, readable by the web servers.
	File string
}

// hotlinkData is hotlink protection as the Nginx template sees it.
type hotlinkData struct {
	// Referers are the site's valid_referers.
	Referers string
	// Pattern matches the protected extensions.
	Pattern    string
	RedirectTo string
}

// mimeData is one content type as the vhost templates see it.
type mimeData struct {
	Extension string
	Type      string
}

func errorPagesData(statuses []int) []errorPageData {
	sorted := slices.Sorted(slices.Values(statuses))
	pages := make([]errorPageData, len(sorted))

	for i, status := range sorted {
		nginx := strconv.Itoa(status)
		if status == 503 {
			nginx = "502 503 504"
		}

		pages[i] = errorPageData{
			Status:        status,
			NginxStatuses: nginx,
			URI:           errorPagesFolder + "/" + strconv.Itoa(status) + ".html",
		}
	}

	return pages
}

// protectedLocation is the Nginx location regular expression of path: the
// folder itself and everything in it, but not /admin2 for /admin.
func protectedLocation(path string) string {
	if path == "/" {
		return "^/"
	}

	return "^" + strings.ReplaceAll(path, ".", `\.`) + "(?:/|$)"
}

func (m *Manager) protectedData(site Site, documentRoot string) []protectedData {
	directories := make([]protectedData, len(site.ProtectedDirectories))

	for i, directory := range site.ProtectedDirectories {
		folder := documentRoot
		if directory.Path != "/" {
			folder += directory.Path
		}

		directories[i] = protectedData{
			Path:      directory.Path,
			Realm:     directory.Realm,
			Location:  protectedLocation(directory.Path),
			Directory: folder,
			File:      m.htpasswdFile(site.User, site.Domain, directory.Path),
		}
	}

	return directories
}

func hotlinkTemplateData(domain string, hotlink *Hotlink) *hotlinkData {
	if hotlink == nil {
		return nil
	}

	referers := []string{"server_names", "*." + domain}
	if hotlink.AllowDirect {
		referers = append([]string{"none", "blocked"}, referers...)
	}

	for _, allowed := range hotlink.Domains {
		referers = append(referers, allowed, "*."+allowed)
	}

	return &hotlinkData{
		Referers:   strings.Join(referers, " "),
		Pattern:    strings.Join(hotlink.Extensions, "|"),
		RedirectTo: hotlink.RedirectTo,
	}
}

// mimeTypesData returns the content types of types whose extension keep
// accepts, all of them when keep is nil.
func mimeTypesData(types []MIMEType, keep func(extension string) bool) []mimeData {
	var data []mimeData

	for _, mime := range types {
		if keep == nil || keep(mime.Extension) {
			data = append(data, mimeData(mime))
		}
	}

	return data
}

// phpPattern matches the extensions site runs as PHP, for Apache's
// FilesMatch.
func phpPattern(extensions []string) string {
	return strings.Join(append(slices.Clone(alwaysPHP), extensions...), "|")
}

// limitExtensions is the security.limit_extensions of a PHP-FPM pool that
// runs extensions on top of .php and .phtml: ".php .phtml .html".
func limitExtensions(extensions []string) string {
	all := append(slices.Clone(alwaysPHP), extensions...)

	for i, extension := range all {
		all[i] = "." + extension
	}

	return strings.Join(all, " ")
}

// htpasswdFile holds the users of the protected directory path of domain.
func (m *Manager) htpasswdFile(user, domain, path string) string {
	return m.nginxAccountDir(user) + "/" + domain + htpasswdSeparator + siteKey(path) + htpasswdSuffix
}

// htpasswdContent is the user file of directory, one name:hash a line.
func htpasswdContent(directory ProtectedDirectory) []byte {
	var content strings.Builder

	for _, user := range directory.Users {
		content.WriteString(user.Name + ":" + user.Hash + "\n")
	}

	return []byte(content.String())
}

// htpasswdChanges writes the user files of the protected directories of
// site, readable by root and the web servers only, and removes those of
// directories it no longer has.
func (m *Manager) htpasswdChanges(site Site) ([]fileChange, error) {
	gid, err := m.webGID()
	if err != nil {
		return nil, err
	}

	changes := make([]fileChange, 0, len(site.ProtectedDirectories))
	wanted := map[string]bool{}

	for _, directory := range site.ProtectedDirectories {
		path := m.htpasswdFile(site.User, site.Domain, directory.Path)
		wanted[path] = true
		changes = append(changes, fileChange{path: path, content: htpasswdContent(directory), mode: 0o640, gid: gid})
	}

	old, err := m.htpasswdFiles(site.User, site.Domain)
	if err != nil {
		return nil, err
	}

	for _, path := range old {
		if !wanted[path] {
			changes = append(changes, fileChange{path: path, mode: 0o640, gid: gid})
		}
	}

	return changes, nil
}

// htpasswdFiles returns the user files of the protected directories domain
// of user has now.
func (m *Manager) htpasswdFiles(user, domain string) ([]string, error) {
	pattern := filepath.Join(m.nginxAccountDir(user), domain+htpasswdSeparator+"*"+htpasswdSuffix)

	paths, err := filepath.Glob(pattern)
	if err != nil {
		return nil, fmt.Errorf("list %s: %w", pattern, err)
	}

	var files []string

	for _, path := range paths {
		if info, err := os.Lstat(path); err == nil && info.Mode().IsRegular() {
			files = append(files, path)
		}
	}

	return files, nil
}

// webGID is the group the web servers run as, which may read the user
// files of protected directories; 0 leaves them in root's group.
func (m *Manager) webGID() (int, error) {
	if m.cfg.WebGroup == "" {
		return 0, nil
	}

	return lookupGroup(m.cfg.WebGroup)
}
