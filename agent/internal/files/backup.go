package files

import (
	"archive/tar"
	"compress/gzip"
	"errors"
	"fmt"
	"io"
	"path"
	"slices"
	"strings"
	"time"
)

const (
	// BackupFolder is the folder at the top of a backup that holds what is
	// not a file of the home: its manifest, the panel's settings of the
	// account, and the dumps of its databases. It is packed first.
	BackupFolder = ".rayxo-backup"

	// BackupManifest and BackupSettings are the two files BackupMeta reads.
	BackupManifest = BackupFolder + "/manifest.json"
	BackupSettings = BackupFolder + "/settings.json"

	// MaxBackupEntries and MaxBackupBytes bound one backup; they are far
	// above those of the file manager, since a backup holds a whole home.
	MaxBackupEntries = 10_000_000
	MaxBackupBytes   = 4 << 40

	// maxBackupMeta bounds the manifest and the settings.
	maxBackupMeta = 16 << 20
)

// BackupMeta is what a backup says about itself.
type BackupMeta struct {
	Manifest string `json:"manifest"`
	Settings string `json:"settings"`
}

// BackupPack packs paths (BackupFolder first, then the files of the home
// the backup holds) into a new tar.gz at destination, as Compress does.
func (h *Home) BackupPack(paths []string, destination string) (Entry, error) {
	if !strings.HasSuffix(destination, ".tar.gz") {
		return Entry{}, errorf(ReasonInvalid, "a backup must be a .tar.gz file")
	}

	return h.compress(paths, destination, FormatTarGz, MaxBackupEntries, true)
}

// BackupExtract unpacks the entries of backup archive whose first folder
// is in include (all of them when include is empty) and not in exclude
// into destination, replacing files that exist. As with Extract, nothing
// can lead out of the home, and links are only made inside it.
func (h *Home) BackupExtract(archive, destination string, include, exclude []string) (ExtractResult, error) {
	if archiveFormat(archive) != FormatTarGz {
		return ExtractResult{Destination: destination}, errorf(ReasonInvalid, "%s is not a .tar.gz backup", archive)
	}

	take := func(name string) bool {
		top, _, _ := strings.Cut(name, "/")

		return (len(include) == 0 || slices.Contains(include, top)) && !slices.Contains(exclude, top)
	}

	return h.extract(archive, destination, extractor{overwrite: true, maxEntries: MaxBackupEntries, maxBytes: MaxBackupBytes, take: take})
}

// BackupMeta reads the manifest and the settings of backup archive. They
// are at its start: once anything else comes, the search stops, so reading
// them never goes through the whole backup.
func (h *Home) BackupMeta(archive string) (BackupMeta, error) {
	file, err := h.openForRead(rootName(archive))
	if err != nil {
		return BackupMeta{}, fail(err, archive)
	}
	defer file.Close()

	info, err := file.Stat()
	if err != nil {
		return BackupMeta{}, fail(err, archive)
	}

	if err := regularFile(info, archive); err != nil {
		return BackupMeta{}, err
	}

	return ReadBackupMeta(file)
}

// ReadBackupMeta reads the manifest and the settings at the start of a
// backup (a tar.gz).
func ReadBackupMeta(backup io.Reader) (BackupMeta, error) {
	var meta BackupMeta

	compressed, err := gzip.NewReader(backup)
	if err != nil {
		return meta, errorf(ReasonInvalid, "the file is not a RAYXO backup")
	}
	defer compressed.Close()

	reader := tar.NewReader(compressed)

	for meta.Manifest == "" || meta.Settings == "" {
		header, err := reader.Next()
		if errors.Is(err, io.EOF) {
			break
		}

		if err != nil {
			return meta, errorf(ReasonInvalid, "the backup is damaged: %v", err)
		}

		name := path.Clean(strings.TrimPrefix(header.Name, "./"))
		if name != BackupFolder && !strings.HasPrefix(name, BackupFolder+"/") {
			break
		}

		if header.Typeflag != tar.TypeReg || (name != BackupManifest && name != BackupSettings) {
			continue
		}

		if header.Size > maxBackupMeta {
			return meta, errorf(ReasonTooLarge, "%s of the backup is too large", name)
		}

		content, err := io.ReadAll(io.LimitReader(reader, maxBackupMeta))
		if err != nil {
			return meta, errorf(ReasonInvalid, "the backup is damaged: %v", err)
		}

		if name == BackupManifest {
			meta.Manifest = string(content)
		} else {
			meta.Settings = string(content)
		}
	}

	if meta.Manifest == "" {
		return meta, errorf(ReasonInvalid, "the file is not a RAYXO backup")
	}

	return meta, nil
}

// BackupStreamed is what BackupStream sent.
type BackupStreamed struct {
	Entries int `json:"entries"`
	// Skipped counts what was left out because it was deleted while the
	// home was read, or the account itself may not read it.
	Skipped int `json:"skipped"`
}

// BackupStream writes paths of the home, folders with all they hold, to
// out as an uncompressed tar stream: the files of a backup the agent keeps
// outside the home (see BackupWriter). Nothing is written into the home.
func (h *Home) BackupStream(out io.Writer, paths []string) (BackupStreamed, error) {
	seen := map[string]bool{}

	for _, p := range paths {
		if err := h.checkSource(p); err != nil {
			return BackupStreamed{}, err
		}

		if seen[path.Base(p)] {
			return BackupStreamed{}, errorf(ReasonInvalid, "two items are named %s", path.Base(p))
		}

		seen[path.Base(p)] = true
	}

	writer := &tarWriter{tar: tar.NewWriter(out)}
	packer := packer{home: h, writer: writer, skip: map[string]bool{}, maxEntries: MaxBackupEntries, live: true}

	for _, p := range paths {
		if err := packer.add(p, path.Base(p)); err != nil {
			return BackupStreamed{}, err
		}
	}

	if err := writer.Close(); err != nil {
		return BackupStreamed{}, err
	}

	return BackupStreamed{Entries: packer.entries - packer.skipped, Skipped: packer.skipped}, nil
}

// BackupUnpack extracts an uncompressed tar stream into destination,
// replacing files that exist: the part of a backup the agent keeps that it
// restores (see CopyBackupEntries). As with Extract, nothing can lead out
// of the home, and links are only made inside it.
func (h *Home) BackupUnpack(stream io.Reader, destination string) (ExtractResult, error) {
	result := ExtractResult{Destination: destination}

	if err := h.refuseTrash(destination); err != nil {
		return result, err
	}

	if err := h.prepareDestination(destination); err != nil {
		return result, err
	}

	x := extractor{home: h, destination: destination, result: &result, overwrite: true, maxEntries: MaxBackupEntries, maxBytes: MaxBackupBytes}

	return result, x.tarStream(stream)
}

// BackupWriter writes a backup the agent keeps outside the home: first
// what is not a file of the home (the manifest, the settings and the dumps
// in BackupFolder), then the tar stream of the home the files helper made
// (BackupStream). The result reads like a backup BackupPack makes.
type BackupWriter struct {
	tar        *tar.Writer
	compressed *gzip.Writer
	entries    int
	bytes      int64
	now        time.Time
}

// NewBackupWriter starts a backup written to w.
func NewBackupWriter(w io.Writer) *BackupWriter {
	compressed := gzip.NewWriter(w)

	return &BackupWriter{tar: tar.NewWriter(compressed), compressed: compressed, now: time.Now()}
}

// AddFolder adds folder name, which is BackupFolder or inside it.
func (b *BackupWriter) AddFolder(name string) error {
	if err := checkBackupMetaName(name); err != nil {
		return err
	}

	return b.write(&tar.Header{Typeflag: tar.TypeDir, Name: name + "/", Mode: 0o755, ModTime: b.now}, nil)
}

// AddFile adds file name, inside BackupFolder, with the size bytes of
// content.
func (b *BackupWriter) AddFile(name string, size int64, content io.Reader) error {
	if err := checkBackupMetaName(name); err != nil {
		return err
	}

	return b.write(&tar.Header{Typeflag: tar.TypeReg, Name: name, Size: size, Mode: 0o644, ModTime: b.now}, content)
}

// AddHome copies the folders, files and links of a tar stream of the home
// into the backup. Entries whose names could lead out of the home, or that
// claim to be in BackupFolder, are left out.
func (b *BackupWriter) AddHome(stream io.Reader) error {
	reader := tar.NewReader(stream)

	for {
		header, err := reader.Next()
		if errors.Is(err, io.EOF) {
			return nil
		}

		if err != nil {
			return fmt.Errorf("read the files of the home: %w", err)
		}

		name, ok := entryName(header.Name)
		if top, _, _ := strings.Cut(name, "/"); !ok || top == BackupFolder {
			continue
		}

		entry := &tar.Header{Name: name, Mode: header.Mode & 0o777, ModTime: header.ModTime}

		switch header.Typeflag {
		case tar.TypeDir:
			entry.Typeflag, entry.Name = tar.TypeDir, name+"/"
		case tar.TypeReg:
			entry.Typeflag, entry.Size = tar.TypeReg, header.Size
		case tar.TypeSymlink:
			entry.Typeflag, entry.Linkname = tar.TypeSymlink, header.Linkname
		default:
			continue
		}

		if err := b.write(entry, reader); err != nil {
			return err
		}
	}
}

// Close finishes the backup; w then holds all of it.
func (b *BackupWriter) Close() error {
	return errors.Join(b.tar.Close(), b.compressed.Close())
}

func (b *BackupWriter) write(header *tar.Header, content io.Reader) error {
	b.entries++
	b.bytes += header.Size

	if b.entries > MaxBackupEntries || b.bytes > MaxBackupBytes {
		return errorf(ReasonTooLarge, "a backup may hold at most %d files and folders and %d TB", MaxBackupEntries, MaxBackupBytes>>40)
	}

	if err := b.tar.WriteHeader(header); err != nil {
		return err
	}

	if header.Typeflag != tar.TypeReg {
		return nil
	}

	if _, err := io.CopyN(b.tar, content, header.Size); err != nil {
		return fmt.Errorf("write %s into the backup: %w", header.Name, err)
	}

	return nil
}

func checkBackupMetaName(name string) error {
	if cleaned, ok := entryName(name); !ok || cleaned != name || (name != BackupFolder && !strings.HasPrefix(name, BackupFolder+"/")) {
		return fmt.Errorf("%q is not a name in %s", name, BackupFolder)
	}

	return nil
}

// CopyBackupEntries reads backup (a tar.gz) and writes the folders, files
// and links pick takes, under the name pick gives them, to out as an
// uncompressed tar stream for BackupUnpack. With metaOnly, reading stops
// at the first entry outside BackupFolder, which comes first.
func CopyBackupEntries(backup io.Reader, out io.Writer, metaOnly bool, pick func(name string) (string, bool)) error {
	compressed, err := gzip.NewReader(backup)
	if err != nil {
		return errorf(ReasonInvalid, "the file is not a RAYXO backup")
	}
	defer compressed.Close()

	reader := tar.NewReader(compressed)
	writer := tar.NewWriter(out)

	for {
		header, err := reader.Next()
		if errors.Is(err, io.EOF) {
			break
		}

		if err != nil {
			return errorf(ReasonInvalid, "the backup is damaged: %v", err)
		}

		name, ok := entryName(header.Name)
		if !ok {
			continue
		}

		if top, _, _ := strings.Cut(name, "/"); metaOnly && top != BackupFolder {
			break
		}

		target, take := pick(name)
		if !take {
			continue
		}

		entry := &tar.Header{Name: target, Mode: header.Mode & 0o777, ModTime: header.ModTime}

		switch header.Typeflag {
		case tar.TypeDir:
			entry.Typeflag, entry.Name = tar.TypeDir, target+"/"
		case tar.TypeReg:
			entry.Typeflag, entry.Size = tar.TypeReg, header.Size
		case tar.TypeSymlink:
			entry.Typeflag, entry.Linkname = tar.TypeSymlink, header.Linkname
		default:
			continue
		}

		if err := writer.WriteHeader(entry); err != nil {
			return err
		}

		if entry.Typeflag == tar.TypeReg {
			if _, err := io.CopyN(writer, reader, header.Size); err != nil {
				return fmt.Errorf("copy %s out of the backup: %w", name, err)
			}
		}
	}

	return writer.Close()
}
