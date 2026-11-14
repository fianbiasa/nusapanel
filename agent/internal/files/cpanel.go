package files

import (
	"archive/tar"
	"compress/gzip"
	"errors"
	"fmt"
	"io"
	"maps"
	"path"
	"slices"
	"strings"
)

// A cPanel backup (cpmove-<user>.tar.gz from pkgacct, or a full backup made
// in cPanel) holds everything under one top folder: what cPanel knows of
// the account (cp/<user>, userdata/, va/, cron/, mysql.sql...), the dumps of
// its databases (mysql/<database>.sql) and its home (homedir/). An import
// reads the small files the panel turns into a RAYXO account, then copies
// the dumps and the home into a RAYXO backup, at the places the panel chose.

const (
	// cpanelHome is the folder of a cPanel backup that holds the home.
	cpanelHome = "homedir"
	// cpanelDumps is the folder that holds the dumps of the databases.
	cpanelDumps = "mysql"
	// maxCpanelFile and maxCpanelFiles bound one file of what cPanel knows
	// of the account, and all of them.
	maxCpanelFile  = 1 << 20
	maxCpanelFiles = 32 << 20
	// maxCpanelHomeNames bounds the names listed at the top of the home.
	maxCpanelHomeNames = 10_000
)

// CpanelInspection is what a cPanel backup holds.
type CpanelInspection struct {
	// Top is the folder everything is in, such as cpmove-<user>.
	Top string `json:"top"`
	// Files are the small files the panel reads, by their name under Top.
	Files map[string]string `json:"files"`
	// Databases are the dumps in mysql/.
	Databases []CpanelDump `json:"databases"`
	// Home are the files and folders at the top of the home.
	Home []CpanelHomeEntry `json:"home"`
	// Entries and Bytes are all the files and folders of the home, and
	// the size of its files.
	Entries int   `json:"entries"`
	Bytes   int64 `json:"bytes"`
}

// CpanelDump is the dump of one database.
type CpanelDump struct {
	Name string `json:"name"`
	Size int64  `json:"size"`
}

// CpanelHomeEntry is a file or folder at the top of the home, with the size
// of the files in it.
type CpanelHomeEntry struct {
	Name  string `json:"name"`
	Type  string `json:"type"`
	Bytes int64  `json:"bytes"`
}

// CpanelRule moves a part of the home of a cPanel backup: what is in From
// (a path in the home) goes into To in the RAYXO home; an empty To leaves
// it out. The rule with the longest From that holds a file applies; files
// no rule holds are left out.
type CpanelRule struct {
	From string `json:"from"`
	To   string `json:"to"`
	// Mail drops the caches Dovecot keeps in a mailbox (indexes and quota
	// counts): Dovecot here makes them again from the mail itself.
	Mail bool `json:"mail"`
}

// CpanelCopied counts what a copy of the home took and left out.
type CpanelCopied struct {
	Entries int `json:"entries"`
	Dropped int `json:"dropped"`
}

// CheckCpanelRule reports whether rule only names paths inside the home.
func CheckCpanelRule(rule CpanelRule) error {
	if name, ok := entryName(rule.From); !ok || name != rule.From {
		return fmt.Errorf("%q is not a path in the home", rule.From)
	}

	if rule.To == "" {
		return nil
	}

	if name, ok := entryName(rule.To); !ok || name != rule.To {
		return fmt.Errorf("%q is not a path in the home", rule.To)
	}

	if top, _, _ := strings.Cut(rule.To, "/"); top == BackupFolder {
		return fmt.Errorf("%q is a folder of the backup itself", rule.To)
	}

	return nil
}

// ReadCpanelBackup reads a cPanel backup (a tar.gz) through.
func ReadCpanelBackup(backup io.Reader) (CpanelInspection, error) {
	inspection := CpanelInspection{Files: map[string]string{}, Databases: []CpanelDump{}, Home: []CpanelHomeEntry{}}
	home := map[string]*CpanelHomeEntry{}
	read := 0

	err := walkCpanel(backup, &inspection.Top, func(rel string, header *tar.Header, content io.Reader) error {
		if inner, ok := strings.CutPrefix(rel, cpanelHome+"/"); ok {
			inspection.Entries++

			top, _, nested := strings.Cut(inner, "/")
			entry := home[top]

			if entry == nil && len(home) < maxCpanelHomeNames {
				entry = &CpanelHomeEntry{Name: top, Type: TypeDirectory}
				home[top] = entry
			}

			if entry != nil && !nested {
				entry.Type = cpanelEntryType(header.Typeflag)
			}

			if header.Typeflag == tar.TypeReg {
				inspection.Bytes += header.Size

				if entry != nil {
					entry.Bytes += header.Size
				}
			}
		}

		if name, ok := strings.CutPrefix(rel, cpanelDumps+"/"); ok && header.Typeflag == tar.TypeReg && path.Ext(name) == ".sql" && !strings.Contains(name, "/") {
			inspection.Databases = append(inspection.Databases, CpanelDump{Name: strings.TrimSuffix(name, ".sql"), Size: header.Size})
		}

		if header.Typeflag != tar.TypeReg || !cpanelMetaFile(rel) || header.Size > maxCpanelFile || read+int(header.Size) > maxCpanelFiles {
			return nil
		}

		data, err := io.ReadAll(io.LimitReader(content, maxCpanelFile))
		if err != nil {
			return errorf(ReasonInvalid, "the backup is damaged: %v", err)
		}

		read += len(data)
		inspection.Files[rel] = string(data)

		return nil
	})
	if err != nil {
		return inspection, err
	}

	if !slices.ContainsFunc(slices.Collect(maps.Keys(inspection.Files)), func(name string) bool { return strings.HasPrefix(name, "cp/") }) {
		return inspection, errorf(ReasonInvalid, "the file is not a cPanel backup")
	}

	for _, entry := range home {
		inspection.Home = append(inspection.Home, *entry)
	}

	slices.SortFunc(inspection.Home, func(a, b CpanelHomeEntry) int { return strings.Compare(a.Name, b.Name) })

	return inspection, nil
}

// CopyCpanelDumps hands the dumps of a cPanel backup named in want (the
// database names) to add, in the order the backup holds them. It stops
// once it found them all, and fails when one is missing.
func CopyCpanelDumps(backup io.Reader, top string, want []string, add func(name string, size int64, content io.Reader) error) error {
	left := map[string]bool{}
	for _, name := range want {
		left[name] = true
	}

	if len(left) == 0 {
		return nil
	}

	errFound := errors.New("found")

	err := walkCpanel(backup, &top, func(rel string, header *tar.Header, content io.Reader) error {
		file, ok := strings.CutPrefix(rel, cpanelDumps+"/")
		name := strings.TrimSuffix(file, ".sql")

		if !ok || header.Typeflag != tar.TypeReg || path.Ext(file) != ".sql" || !left[name] {
			return nil
		}

		delete(left, name)

		if err := add(name, header.Size, content); err != nil {
			return err
		}

		if len(left) == 0 {
			return errFound
		}

		return nil
	})

	switch {
	case errors.Is(err, errFound):
		return nil
	case err != nil:
		return err
	case len(left) > 0:
		return errorf(ReasonNotFound, "the backup holds no dump of %s", strings.Join(slices.Sorted(maps.Keys(left)), ", "))
	}

	return nil
}

// AddCpanelHome copies the home of a cPanel backup into the backup, each
// file where rules put it. A folder a rule moves elsewhere is not copied
// itself, so the one here keeps its own permissions.
func (b *BackupWriter) AddCpanelHome(backup io.Reader, top string, rules []CpanelRule) (CpanelCopied, error) {
	var copied CpanelCopied

	err := walkCpanel(backup, &top, func(rel string, header *tar.Header, content io.Reader) error {
		inner, ok := strings.CutPrefix(rel, cpanelHome+"/")
		if !ok {
			return nil
		}

		rule, found := cpanelRuleFor(rules, inner)
		if !found || rule.To == "" || (rule.Mail && cpanelMailCache(path.Base(inner))) {
			copied.Dropped++

			return nil
		}

		if inner == rule.From && rule.From != rule.To && header.Typeflag == tar.TypeDir {
			return nil
		}

		target, ok := entryName(rule.To + strings.TrimPrefix(inner, rule.From))
		if first, _, _ := strings.Cut(target, "/"); !ok || first == BackupFolder {
			copied.Dropped++

			return nil
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
			copied.Dropped++

			return nil
		}

		copied.Entries++

		return b.write(entry, content)
	})

	return copied, err
}

// walkCpanel hands every entry of a cPanel backup (a tar.gz) to visit, by
// its name under the top folder. top is the folder all entries must be in;
// an empty one becomes that of the first entry.
func walkCpanel(backup io.Reader, top *string, visit func(rel string, header *tar.Header, content io.Reader) error) error {
	compressed, err := gzip.NewReader(backup)
	if err != nil {
		return errorf(ReasonInvalid, "the file is not a cPanel backup")
	}
	defer compressed.Close()

	reader := tar.NewReader(compressed)

	for {
		header, err := reader.Next()
		if errors.Is(err, io.EOF) {
			return nil
		}

		if err != nil {
			return errorf(ReasonInvalid, "the backup is damaged: %v", err)
		}

		name, ok := entryName(header.Name)
		if !ok {
			continue
		}

		first, rel, _ := strings.Cut(name, "/")

		if *top == "" {
			*top = first
		}

		if first != *top {
			return errorf(ReasonInvalid, "the file is not a cPanel backup: it holds more than the folder %s", *top)
		}

		if rel == "" {
			continue
		}

		if err := visit(rel, header, reader); err != nil {
			return err
		}
	}
}

// cpanelMetaFile reports whether rel is one of the small files the panel
// reads: the account (cp/), its domains (userdata/), forwarders (va/),
// cron jobs (cron/), database users (mysql.sql) and mailboxes (the shadow
// and quota files of each mail domain in the home).
func cpanelMetaFile(rel string) bool {
	switch rel {
	case "version", "homedir_paths", "mysql.sql", "mysql.sql-auth.json":
		return true
	}

	parts := strings.Split(rel, "/")

	switch len(parts) {
	case 2:
		return slices.Contains([]string{"cp", "userdata", "va", "cron"}, parts[0])
	case 4:
		return parts[0] == cpanelHome && parts[1] == "etc" && (parts[3] == "shadow" || parts[3] == "quota")
	}

	return false
}

// cpanelRuleFor is the rule with the longest From that holds inner.
func cpanelRuleFor(rules []CpanelRule, inner string) (CpanelRule, bool) {
	var best CpanelRule

	found := false

	for _, rule := range rules {
		if (inner == rule.From || strings.HasPrefix(inner, rule.From+"/")) && (!found || len(rule.From) > len(best.From)) {
			best, found = rule, true
		}
	}

	return best, found
}

// cpanelMailCache reports whether name is a cache Dovecot keeps in a
// mailbox and makes again by itself.
func cpanelMailCache(name string) bool {
	return strings.HasPrefix(name, "dovecot.index") || strings.HasPrefix(name, "dovecot.list.index") ||
		name == "dovecot.mailbox.log" || name == "dovecot-quota" || name == "maildirsize"
}

func cpanelEntryType(flag byte) string {
	switch flag {
	case tar.TypeDir:
		return TypeDirectory
	case tar.TypeReg:
		return TypeFile
	case tar.TypeSymlink:
		return TypeLink
	}

	return TypeOther
}
