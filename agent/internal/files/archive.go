package files

import (
	"archive/tar"
	"archive/zip"
	"compress/bzip2"
	"compress/gzip"
	"errors"
	"io"
	"io/fs"
	"os"
	"path"
	"strings"
	"time"
)

const (
	// Formats Compress writes.
	FormatZip   = "zip"
	FormatTarGz = "tar.gz"

	// zipCreatorUnix and zipCreatorMacOS are the systems whose zip files
	// carry Unix permissions.
	zipCreatorUnix  = 3
	zipCreatorMacOS = 19

	// MaxArchiveEntries and MaxExtractBytes bound one archive, so an
	// archive that expands to millions of files or terabytes stops early.
	MaxArchiveEntries = 100_000
	MaxExtractBytes   = 50 << 30
)

// Compress packs paths, folders with all they hold, into a new archive at
// destination. Every path keeps its own name at the top of the archive;
// links are stored as links.
func (h *Home) Compress(paths []string, destination, format string) (Entry, error) {
	return h.compress(paths, destination, format, MaxArchiveEntries, false)
}

// compress is Compress with its own bound on the number of entries. A live
// archive (a backup of a home in use) leaves out what it cannot read.
func (h *Home) compress(paths []string, destination, format string, maxEntries int, live bool) (Entry, error) {
	if err := h.checkSource(destination); err != nil {
		return Entry{}, err
	}

	if err := h.checkFolder(parent(destination)); err != nil {
		return Entry{}, err
	}

	if err := h.free(destination); err != nil {
		return Entry{}, err
	}

	seen := map[string]bool{}

	for _, p := range paths {
		if err := h.checkSource(p); err != nil {
			return Entry{}, err
		}

		if _, err := h.root.Lstat(p); err != nil {
			return Entry{}, fail(err, p)
		}

		if seen[path.Base(p)] {
			return Entry{}, errorf(ReasonInvalid, "two items are named %s", path.Base(p))
		}

		seen[path.Base(p)] = true
	}

	temp := join(parent(destination), ".rayxo-"+randomHex(8)+".tmp")

	file, err := h.root.OpenFile(temp, os.O_WRONLY|os.O_CREATE|os.O_EXCL, newFileMode)
	if err != nil {
		return Entry{}, fail(err, destination)
	}

	writer := newArchiveWriter(file, format)
	packer := packer{home: h, writer: writer, skip: map[string]bool{temp: true, destination: true}, maxEntries: maxEntries, live: live}

	for _, p := range paths {
		if err = packer.add(p, path.Base(p)); err != nil {
			break
		}
	}

	if closeErr := writer.Close(); err == nil {
		err = closeErr
	}

	if err == nil {
		err = file.Sync()
	}

	if closeErr := file.Close(); err == nil {
		err = closeErr
	}

	if err == nil {
		err = h.root.Rename(temp, destination)
	}

	if err != nil {
		_ = h.root.Remove(temp)

		return Entry{}, fail(err, destination)
	}

	info, err := h.root.Lstat(destination)
	if err != nil {
		return Entry{}, fail(err, destination)
	}

	return h.entry(destination, info), nil
}

// packer walks the home and adds what it finds to an archive.
type packer struct {
	home       *Home
	writer     archiveWriter
	skip       map[string]bool
	entries    int
	maxEntries int
	// live packs a home in use: what is deleted while it is packed, or
	// what the account itself may not read, is left out and counted in
	// skipped instead of stopping the archive.
	live    bool
	skipped int
}

// passOver reports whether a live archive leaves out the path err is about.
func (p *packer) passOver(err error) bool {
	if !p.live {
		return false
	}

	var failure *Error

	unreadable := errors.Is(err, fs.ErrNotExist) || errors.Is(err, fs.ErrPermission) ||
		(errors.As(err, &failure) && (failure.Reason == ReasonNotFound || failure.Reason == ReasonPermission))

	if unreadable {
		p.skipped++
	}

	return unreadable
}

func (p *packer) add(src, name string) error {
	if p.skip[src] {
		return nil
	}

	p.entries++
	if p.entries > p.maxEntries {
		return errorf(ReasonTooLarge, "an archive may hold at most %d files and folders", p.maxEntries)
	}

	info, err := p.home.root.Lstat(src)
	if err != nil {
		if p.passOver(err) {
			return nil
		}

		return fail(err, src)
	}

	switch {
	case info.Mode()&fs.ModeSymlink != 0:
		target, err := p.home.root.Readlink(src)
		if err != nil {
			if p.passOver(err) {
				return nil
			}

			return fail(err, src)
		}

		return p.writer.addLink(name, target, info)

	case info.IsDir():
		if err := p.writer.addDir(name, info); err != nil {
			return err
		}

		names, err := p.home.names(src)
		if err != nil {
			if p.passOver(err) {
				return nil
			}

			return err
		}

		for _, child := range names {
			if err := p.add(join(src, child), name+"/"+child); err != nil {
				return err
			}
		}

		return nil

	case info.Mode().IsRegular():
		file, err := p.home.openRegular(src)
		if err != nil {
			if p.passOver(err) {
				return nil
			}

			return fail(err, src)
		}
		defer file.Close()

		return p.writer.addFile(name, info, file)
	}

	// Sockets, pipes and devices are left out.
	return nil
}

// archiveWriter writes one archive format.
type archiveWriter interface {
	addDir(name string, info fs.FileInfo) error
	addFile(name string, info fs.FileInfo, r io.Reader) error
	addLink(name, target string, info fs.FileInfo) error
	Close() error
}

func newArchiveWriter(w io.Writer, format string) archiveWriter {
	if format == FormatTarGz {
		compressed := gzip.NewWriter(w)

		return &tarWriter{tar: tar.NewWriter(compressed), compressed: compressed}
	}

	return &zipWriter{zip: zip.NewWriter(w)}
}

type zipWriter struct {
	zip *zip.Writer
}

func (z *zipWriter) header(name string, info fs.FileInfo) (*zip.FileHeader, error) {
	header, err := zip.FileInfoHeader(info)
	if err != nil {
		return nil, err
	}

	header.Name = name

	return header, nil
}

func (z *zipWriter) addDir(name string, info fs.FileInfo) error {
	header, err := z.header(name+"/", info)
	if err != nil {
		return err
	}

	_, err = z.zip.CreateHeader(header)

	return err
}

func (z *zipWriter) addFile(name string, info fs.FileInfo, r io.Reader) error {
	header, err := z.header(name, info)
	if err != nil {
		return err
	}

	header.Method = zip.Deflate

	w, err := z.zip.CreateHeader(header)
	if err != nil {
		return err
	}

	_, err = io.Copy(w, r)

	return err
}

func (z *zipWriter) addLink(name, target string, info fs.FileInfo) error {
	header, err := z.header(name, info)
	if err != nil {
		return err
	}

	w, err := z.zip.CreateHeader(header)
	if err != nil {
		return err
	}

	_, err = io.WriteString(w, target)

	return err
}

func (z *zipWriter) Close() error {
	return z.zip.Close()
}

// tarWriter writes a tar archive, compressed with gzip unless compressed
// is nil (the stream a backup of the home is sent to the agent in).
type tarWriter struct {
	tar        *tar.Writer
	compressed *gzip.Writer
}

func (t *tarWriter) header(name, link string, info fs.FileInfo) (*tar.Header, error) {
	header, err := tar.FileInfoHeader(info, link)
	if err != nil {
		return nil, err
	}

	// Owners mean nothing on another server; the archive keeps names and
	// permissions only.
	header.Name = name
	header.Uid, header.Gid, header.Uname, header.Gname = 0, 0, "", ""

	return header, nil
}

func (t *tarWriter) addDir(name string, info fs.FileInfo) error {
	header, err := t.header(name+"/", "", info)
	if err != nil {
		return err
	}

	return t.tar.WriteHeader(header)
}

func (t *tarWriter) addFile(name string, info fs.FileInfo, r io.Reader) error {
	header, err := t.header(name, "", info)
	if err != nil {
		return err
	}

	if err := t.tar.WriteHeader(header); err != nil {
		return err
	}

	// The header holds the size the file had: one that grew while it was
	// read is cut there, and one that shrank (a log rotated meanwhile) is
	// padded with zeros, as GNU tar does.
	written, err := io.Copy(t.tar, io.LimitReader(r, info.Size()))
	if err == nil && written < info.Size() {
		_, err = io.CopyN(t.tar, zeros{}, info.Size()-written)
	}

	return err
}

// zeros reads as an endless run of zero bytes.
type zeros struct{}

func (zeros) Read(p []byte) (int, error) {
	clear(p)

	return len(p), nil
}

func (t *tarWriter) addLink(name, target string, info fs.FileInfo) error {
	header, err := t.header(name, target, info)
	if err != nil {
		return err
	}

	return t.tar.WriteHeader(header)
}

func (t *tarWriter) Close() error {
	err := t.tar.Close()
	if t.compressed == nil {
		return err
	}

	if closeErr := t.compressed.Close(); err == nil {
		err = closeErr
	}

	return err
}

// ExtractResult is what extracting an archive did. Skipped entries already
// existed (without overwrite); refused ones had names that lead out of the
// folder, or were special files.
type ExtractResult struct {
	Destination string `json:"destination"`
	Files       int    `json:"files"`
	Folders     int    `json:"folders"`
	Links       int    `json:"links"`
	Skipped     int    `json:"skipped"`
	Refused     int    `json:"refused"`
}

// archiveEntry is one entry of an archive being extracted.
type archiveEntry struct {
	name     string
	mode     fs.FileMode
	modified time.Time
	kind     string
	target   string
	open     func() (io.ReadCloser, error)
}

// IsArchive reports whether name is an archive Extract can open.
func IsArchive(name string) bool {
	return archiveFormat(name) != ""
}

func archiveFormat(name string) string {
	lower := strings.ToLower(name)

	switch {
	case strings.HasSuffix(lower, ".zip"):
		return "zip"
	case strings.HasSuffix(lower, ".tar"):
		return "tar"
	case strings.HasSuffix(lower, ".tar.gz"), strings.HasSuffix(lower, ".tgz"):
		return "tar.gz"
	case strings.HasSuffix(lower, ".tar.bz2"), strings.HasSuffix(lower, ".tbz2"), strings.HasSuffix(lower, ".tbz"):
		return "tar.bz2"
	}

	return ""
}

// Extract unpacks archive into folder destination, which is created when
// it does not exist. Existing files are only replaced with overwrite.
// Entry names that would lead out of destination are refused, and links
// in the archive can never lead a later entry out of the home.
func (h *Home) Extract(archive, destination string, overwrite bool) (ExtractResult, error) {
	return h.extract(archive, destination, extractor{overwrite: overwrite, maxEntries: MaxArchiveEntries, maxBytes: MaxExtractBytes})
}

// extract is Extract with the settings of x: whether files are replaced,
// the bounds, and which entries are taken.
func (h *Home) extract(archive, destination string, x extractor) (ExtractResult, error) {
	result := ExtractResult{Destination: destination}

	format := archiveFormat(archive)
	if format == "" {
		return result, errorf(ReasonInvalid, "%s is not a zip or tar archive", archive)
	}

	if err := h.refuseTrash(destination); err != nil {
		return result, err
	}

	file, err := h.openForRead(rootName(archive))
	if err != nil {
		return result, fail(err, archive)
	}
	defer file.Close()

	info, err := file.Stat()
	if err != nil {
		return result, fail(err, archive)
	}

	if err := regularFile(info, archive); err != nil {
		return result, err
	}

	if err := h.prepareDestination(destination); err != nil {
		return result, err
	}

	x.home, x.destination, x.result = h, destination, &result

	if format == "zip" {
		err = x.zip(file, info.Size())
	} else {
		err = x.tar(file, format)
	}

	return result, err
}

// prepareDestination creates the folder an archive is extracted into, and
// checks that it is a folder of the home.
func (h *Home) prepareDestination(destination string) error {
	if destination != "" {
		if err := h.root.MkdirAll(destination, newDirMode); err != nil {
			return fail(err, destination)
		}
	}

	return h.checkFolder(destination)
}

type extractor struct {
	home        *Home
	destination string
	overwrite   bool
	result      *ExtractResult
	entries     int
	bytes       int64
	maxEntries  int
	maxBytes    int64
	// take, when set, says which entries (by their cleaned name) are
	// extracted; the others are passed over without being counted.
	take func(name string) bool
}

func (x *extractor) zip(file *os.File, size int64) error {
	reader, err := zip.NewReader(file, size)
	if err != nil {
		return errorf(ReasonInvalid, "the archive is damaged or not a zip file")
	}

	for _, f := range reader.File {
		mode := f.Mode()
		entry := archiveEntry{name: f.Name, mode: mode, modified: f.Modified}

		// Zip files made on Windows carry no permissions (Go reports 0666 or
		// 0777): they get the defaults, never world-writable files.
		if creator := f.CreatorVersion >> 8; creator != zipCreatorUnix && creator != zipCreatorMacOS {
			entry.mode = mode.Type()
		}

		switch {
		case mode&fs.ModeSymlink != 0:
			entry.kind = TypeLink

			target, err := readLimited(f.Open, 4096)
			if err != nil {
				return errorf(ReasonInvalid, "the archive is damaged: %v", err)
			}

			entry.target = target
		case mode.IsDir() || strings.HasSuffix(f.Name, "/"):
			entry.kind = TypeDirectory
		case mode.IsRegular():
			entry.kind = TypeFile
			entry.open = func() (io.ReadCloser, error) { return f.Open() }
		default:
			entry.kind = TypeOther
		}

		if err := x.place(entry); err != nil {
			return err
		}
	}

	return nil
}

func (x *extractor) tar(file *os.File, format string) error {
	var source io.Reader = file

	switch format {
	case "tar.gz":
		compressed, err := gzip.NewReader(file)
		if err != nil {
			return errorf(ReasonInvalid, "the archive is damaged or not a gzip file")
		}
		defer compressed.Close()

		source = compressed
	case "tar.bz2":
		source = bzip2.NewReader(file)
	}

	return x.tarStream(source)
}

// tarStream extracts the entries of an uncompressed tar stream.
func (x *extractor) tarStream(source io.Reader) error {
	reader := tar.NewReader(source)

	for {
		header, err := reader.Next()
		if errors.Is(err, io.EOF) {
			return nil
		}

		if err != nil {
			return errorf(ReasonInvalid, "the archive is damaged: %v", err)
		}

		entry := archiveEntry{name: header.Name, mode: fs.FileMode(header.Mode).Perm(), modified: header.ModTime}

		switch header.Typeflag {
		case tar.TypeDir:
			entry.kind = TypeDirectory
		case tar.TypeReg:
			entry.kind = TypeFile
			entry.open = func() (io.ReadCloser, error) { return io.NopCloser(reader), nil }
		case tar.TypeSymlink:
			entry.kind = TypeLink
			entry.target = header.Linkname
		default:
			// Hard links, devices and pipes are not extracted.
			entry.kind = TypeOther
		}

		if err := x.place(entry); err != nil {
			return err
		}
	}
}

// place extracts one entry.
func (x *extractor) place(entry archiveEntry) error {
	name, ok := entryName(entry.name)
	if ok && x.take != nil && !x.take(name) {
		return nil
	}

	x.entries++
	if x.entries > x.maxEntries {
		return errorf(ReasonTooLarge, "the archive holds more than %d files and folders", x.maxEntries)
	}

	if !ok || entry.kind == TypeOther {
		x.result.Refused++

		return nil
	}

	target := join(x.destination, name)
	root := x.home.root

	if dir := parent(target); dir != "" && dir != x.destination {
		if err := root.MkdirAll(dir, newDirMode); err != nil {
			return fail(err, dir)
		}
	}

	existing, err := root.Lstat(target)
	exists := err == nil

	if err != nil && !errors.Is(err, fs.ErrNotExist) {
		return fail(err, target)
	}

	switch entry.kind {
	case TypeDirectory:
		if exists {
			if !existing.IsDir() {
				x.result.Skipped++
			}

			return nil
		}

		if err := root.Mkdir(target, permOr(entry.mode, newDirMode)|0o700); err != nil {
			return fail(err, target)
		}

		x.result.Folders++

	case TypeLink:
		if exists && (!x.overwrite || existing.IsDir()) {
			x.result.Skipped++

			return nil
		}

		if exists {
			if err := root.Remove(target); err != nil {
				return fail(err, target)
			}
		}

		if err := root.Symlink(entry.target, target); err != nil {
			return fail(err, target)
		}

		x.result.Links++

	case TypeFile:
		if exists && (!x.overwrite || !existing.Mode().IsRegular()) {
			x.result.Skipped++

			return nil
		}

		content, err := entry.open()
		if err != nil {
			return errorf(ReasonInvalid, "the archive is damaged: %v", err)
		}

		err = x.home.replace(target, permOr(entry.mode, newFileMode)|0o600, &limitedReader{reader: content, extractor: x})
		_ = content.Close()

		if err != nil {
			return err
		}

		if !entry.modified.IsZero() {
			_ = root.Chtimes(target, entry.modified, entry.modified)
		}

		x.result.Files++
	}

	return nil
}

// permOr returns the permission bits of mode, or fallback when the archive
// has none (zip files made on Windows), so extracted websites stay readable.
func permOr(mode, fallback fs.FileMode) fs.FileMode {
	if mode.Perm() == 0 {
		return fallback
	}

	return mode.Perm()
}

// limitedReader stops an extraction once it wrote MaxExtractBytes.
type limitedReader struct {
	reader    io.Reader
	extractor *extractor
}

func (l *limitedReader) Read(p []byte) (int, error) {
	n, err := l.reader.Read(p)
	l.extractor.bytes += int64(n)

	if l.extractor.bytes > l.extractor.maxBytes {
		return n, errorf(ReasonTooLarge, "the archive expands to more than %d GB", l.extractor.maxBytes>>30)
	}

	return n, err
}

// entryName turns the name of an archive entry into a path relative to
// the extraction folder, or refuses it when it could lead out of it.
func entryName(name string) (string, bool) {
	name = strings.TrimPrefix(strings.ReplaceAll(name, "\\", "/"), "./")

	if name == "" || strings.HasPrefix(name, "/") {
		return "", false
	}

	cleaned := path.Clean(name)
	if cleaned == "." || cleaned == ".." || strings.HasPrefix(cleaned, "../") {
		return "", false
	}

	if CheckPath(cleaned) != nil {
		return "", false
	}

	return cleaned, true
}

func readLimited(open func() (io.ReadCloser, error), limit int64) (string, error) {
	reader, err := open()
	if err != nil {
		return "", err
	}
	defer reader.Close()

	data, err := io.ReadAll(io.LimitReader(reader, limit))

	return string(data), err
}
