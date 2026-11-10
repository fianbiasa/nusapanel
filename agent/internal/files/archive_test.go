package files

import (
	"archive/tar"
	"archive/zip"
	"bytes"
	"compress/gzip"
	"os"
	"path/filepath"
	"slices"
	"testing"
)

func TestCompressAndExtractKeepContentModesAndLinks(t *testing.T) {
	for _, format := range []string{FormatZip, FormatTarGz} {
		t.Run(format, func(t *testing.T) {
			h, home, _ := newHome(t)

			writeFile(t, filepath.Join(home, "site/index.php"), "<?php echo 1;", 0o640)
			writeFile(t, filepath.Join(home, "site/css/app.css"), "body{}", 0o644)

			if err := os.Symlink("index.php", filepath.Join(home, "site/home.php")); err != nil {
				t.Fatal(err)
			}

			archive := "site/backup." + format

			entry, err := h.Compress([]string{"site"}, archive, format)
			if err != nil {
				t.Fatal(err)
			}

			if entry.Size == 0 {
				t.Fatalf("archive = %+v", entry)
			}

			result, err := h.Extract(archive, "copy", false)
			if err != nil {
				t.Fatal(err)
			}

			// The archive does not hold itself, though it was written into
			// the folder being packed.
			if result.Files != 2 || result.Folders != 2 || result.Links != 1 || result.Refused != 0 {
				t.Fatalf("extract = %+v", result)
			}

			if readFile(t, filepath.Join(home, "copy/site/css/app.css")) != "body{}" {
				t.Fatal("content differs")
			}

			if info, _ := os.Stat(filepath.Join(home, "copy/site/index.php")); info.Mode().Perm() != 0o640 {
				t.Fatalf("mode = %o", info.Mode().Perm())
			}

			if target, _ := os.Readlink(filepath.Join(home, "copy/site/home.php")); target != "index.php" {
				t.Fatalf("link = %q", target)
			}

			if _, err := os.Stat(filepath.Join(home, "copy/site/backup."+format)); !os.IsNotExist(err) {
				t.Fatal("the archive packed itself")
			}

			// Again: nothing is overwritten without being asked.
			writeFile(t, filepath.Join(home, "copy/site/index.php"), "changed", 0o640)

			result, err = h.Extract(archive, "copy", false)
			if err != nil || result.Skipped != 3 || result.Files != 0 {
				t.Fatalf("extract again = %+v, %v", result, err)
			}

			if readFile(t, filepath.Join(home, "copy/site/index.php")) != "changed" {
				t.Fatal("overwritten without being asked")
			}

			if _, err := h.Extract(archive, "copy", true); err != nil {
				t.Fatal(err)
			}

			if readFile(t, filepath.Join(home, "copy/site/index.php")) != "<?php echo 1;" {
				t.Fatal("not overwritten")
			}
		})
	}
}

func TestCompressRefusals(t *testing.T) {
	h, home, _ := newHome(t)

	writeFile(t, filepath.Join(home, "a.txt"), "a", 0o644)
	writeFile(t, filepath.Join(home, "x.zip"), "zip", 0o644)

	_, err := h.Compress([]string{"a.txt"}, "x.zip", FormatZip)
	wantReason(t, err, ReasonExists)

	_, err = h.Compress([]string{""}, "home.zip", FormatZip)
	wantReason(t, err, ReasonInvalid)

	_, err = h.Compress([]string{"missing"}, "m.zip", FormatZip)
	wantReason(t, err, ReasonNotFound)

	if leftovers, _ := filepath.Glob(filepath.Join(home, ".rayxo-*")); len(leftovers) != 0 {
		t.Fatalf("temporary files left: %v", leftovers)
	}
}

func TestExtractRefusesEntriesThatLeaveTheFolder(t *testing.T) {
	h, home, outside := newHome(t)

	var buf bytes.Buffer
	writer := zip.NewWriter(&buf)

	for _, name := range []string{"../evil.txt", "/abs.txt", "ok/../../evil2.txt", "good.txt", "dir/inner.txt"} {
		w, err := writer.Create(name)
		if err != nil {
			t.Fatal(err)
		}

		_, _ = w.Write([]byte(name))
	}

	// A file without permission bits, as zip files made on Windows have.
	w, _ := writer.CreateHeader(&zip.FileHeader{Name: "windows.txt", Method: zip.Deflate})
	_, _ = w.Write([]byte("windows"))
	_ = writer.Close()

	writeFile(t, filepath.Join(home, "upload.zip"), buf.String(), 0o644)

	result, err := h.Extract("upload.zip", "target", false)
	if err != nil {
		t.Fatal(err)
	}

	if result.Refused != 3 || result.Files != 3 {
		t.Fatalf("extract = %+v", result)
	}

	if readFile(t, filepath.Join(home, "target/good.txt")) != "good.txt" {
		t.Fatal("good entry not extracted")
	}

	if info, _ := os.Stat(filepath.Join(home, "target/windows.txt")); info.Mode().Perm() != 0o644 {
		t.Fatalf("mode of a file without permissions = %o", info.Mode().Perm())
	}

	for _, path := range []string{filepath.Join(home, "evil.txt"), filepath.Join(filepath.Dir(home), "evil.txt"), filepath.Join(outside, "evil2.txt")} {
		if _, err := os.Stat(path); !os.IsNotExist(err) {
			t.Fatalf("%s was written", path)
		}
	}
}

func TestExtractNeverFollowsALinkOutOfTheHome(t *testing.T) {
	h, home, outside := newHome(t)

	var buf bytes.Buffer
	compressed := gzip.NewWriter(&buf)
	writer := tar.NewWriter(compressed)

	_ = writer.WriteHeader(&tar.Header{Name: "out", Typeflag: tar.TypeSymlink, Linkname: outside, Mode: 0o777})
	_ = writer.WriteHeader(&tar.Header{Name: "out/pwned.txt", Typeflag: tar.TypeReg, Mode: 0o644, Size: 5})
	_, _ = writer.Write([]byte("pwned"))
	_ = writer.Close()
	_ = compressed.Close()

	writeFile(t, filepath.Join(home, "evil.tar.gz"), buf.String(), 0o644)

	_, err := h.Extract("evil.tar.gz", "", false)
	wantReason(t, err, ReasonOutside)

	if entries, _ := os.ReadDir(outside); len(entries) != 0 {
		t.Fatalf("outside now holds %d entries", len(entries))
	}

	_, err = h.Extract("missing.zip", "", false)
	wantReason(t, err, ReasonNotFound)

	writeFile(t, filepath.Join(home, "notes.txt"), "x", 0o644)
	_, err = h.Extract("notes.txt", "", false)
	wantReason(t, err, ReasonInvalid)
}

func TestSearchFindsNamesAndSkipsTheTrashAndLinks(t *testing.T) {
	h, home, outside := newHome(t)

	writeFile(t, filepath.Join(home, "site/Config.php"), "x", 0o644)
	writeFile(t, filepath.Join(home, "site/deep/old-config.bak"), "x", 0o644)
	writeFile(t, filepath.Join(home, ".trash/20261110-093000-0a1b2c3d/config.php"), "x", 0o644)
	writeFile(t, filepath.Join(outside, "config.php"), "x", 0o644)

	if err := os.Symlink(outside, filepath.Join(home, "site/out")); err != nil {
		t.Fatal(err)
	}

	result, err := h.Search("", "CONFIG")
	if err != nil {
		t.Fatal(err)
	}

	var paths []string
	for _, hit := range result.Hits {
		paths = append(paths, hit.Path)
	}

	if !slices.Equal(paths, []string{"site/Config.php", "site/deep/old-config.bak"}) || result.Truncated {
		t.Fatalf("hits = %v", paths)
	}

	if err := CheckQuery("a/b"); err == nil {
		t.Fatal("accepted a query with a slash")
	}
}

func TestDiskUsageCountsWithoutFollowingLinks(t *testing.T) {
	h, home, outside := newHome(t)

	writeFile(t, filepath.Join(home, "site/a.txt"), "12345", 0o644)
	writeFile(t, filepath.Join(home, "site/sub/b.txt"), "123", 0o644)
	writeFile(t, filepath.Join(outside, "big"), "0123456789", 0o644)

	if err := os.Symlink(outside, filepath.Join(home, "site/out")); err != nil {
		t.Fatal(err)
	}

	result := h.DiskUsage([]string{"site", "missing"})

	if len(result.Usage) != 1 || len(result.Failed) != 1 || result.Failed[0].Reason != ReasonNotFound {
		t.Fatalf("usage = %+v", result)
	}

	if usage := result.Usage[0]; usage.Size != 8 || usage.Files != 3 || usage.Folders != 1 {
		t.Fatalf("site = %+v", usage)
	}
}
