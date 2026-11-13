package files

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestSizesListsTheBiggestFirstWithTheLargestFiles(t *testing.T) {
	h, home, outside := newHome(t)

	writeFile(t, filepath.Join(home, "site/big.bin"), strings.Repeat("x", 3000), 0o644)
	writeFile(t, filepath.Join(home, "site/css/a.css"), strings.Repeat("x", 10), 0o644)
	writeFile(t, filepath.Join(home, "notes.txt"), strings.Repeat("x", 500), 0o644)
	writeFile(t, filepath.Join(home, ".trash/old.zip"), strings.Repeat("x", 100), 0o644)
	writeFile(t, filepath.Join(outside, "huge"), strings.Repeat("x", 9000), 0o644)

	// A link out of the home is counted, never followed.
	if err := os.Symlink(outside, filepath.Join(home, "elsewhere")); err != nil {
		t.Fatal(err)
	}

	sizes, err := h.Sizes("")
	if err != nil {
		t.Fatal(err)
	}

	var order []string
	for _, entry := range sizes.Entries {
		order = append(order, entry.Name)
	}

	if strings.Join(order, " ") != "site notes.txt .trash elsewhere" {
		t.Errorf("entries = %v", order)
	}

	site := sizes.Entries[0]
	if site.Size != 3010 || site.Files != 2 || site.Folders != 1 || site.Type != TypeDirectory {
		t.Errorf("site = %+v", site)
	}

	if sizes.Size != 3610 || sizes.Files != 5 || sizes.Folders != 3 || sizes.Truncated {
		t.Errorf("totals = size %d, files %d, folders %d, truncated %v", sizes.Size, sizes.Files, sizes.Folders, sizes.Truncated)
	}

	if len(sizes.Largest) != 4 || sizes.Largest[0].Path != "site/big.bin" || sizes.Largest[0].Size != 3000 || sizes.Largest[3].Path != "site/css/a.css" {
		t.Errorf("largest = %+v", sizes.Largest)
	}
}

func TestSizesKeepsOnlyTheLargestFiles(t *testing.T) {
	h, home, _ := newHome(t)

	for i := range MaxLargestFiles + 10 {
		writeFile(t, filepath.Join(home, "files", strings.Repeat("f", i+1)), strings.Repeat("x", i+1), 0o644)
	}

	sizes, err := h.Sizes("files")
	if err != nil {
		t.Fatal(err)
	}

	if len(sizes.Largest) != MaxLargestFiles || sizes.Largest[0].Size != MaxLargestFiles+10 || sizes.Largest[MaxLargestFiles-1].Size != 11 {
		t.Errorf("largest: %d files, from %d to %d bytes", len(sizes.Largest), sizes.Largest[0].Size, sizes.Largest[len(sizes.Largest)-1].Size)
	}
}

func TestSizesNeedsAFolderInTheHome(t *testing.T) {
	h, home, _ := newHome(t)
	writeFile(t, filepath.Join(home, "a.txt"), "x", 0o644)

	for _, p := range []string{"a.txt", "missing", "../outside"} {
		if _, err := h.Sizes(p); err == nil {
			t.Errorf("Sizes(%q) succeeded", p)
		}
	}
}
