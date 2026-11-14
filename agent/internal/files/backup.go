package files

import (
	"archive/tar"
	"compress/gzip"
	"errors"
	"io"
	"path"
	"slices"
	"strings"
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

	return h.compress(paths, destination, FormatTarGz, MaxBackupEntries)
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
	var meta BackupMeta

	file, err := h.openForRead(rootName(archive))
	if err != nil {
		return meta, fail(err, archive)
	}
	defer file.Close()

	info, err := file.Stat()
	if err != nil {
		return meta, fail(err, archive)
	}

	if err := regularFile(info, archive); err != nil {
		return meta, err
	}

	compressed, err := gzip.NewReader(file)
	if err != nil {
		return meta, errorf(ReasonInvalid, "%s is not a RAYXO backup", archive)
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
		return meta, errorf(ReasonInvalid, "%s is not a RAYXO backup", archive)
	}

	return meta, nil
}
