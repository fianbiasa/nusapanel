package files

import (
	"cmp"
	"container/heap"
	"io/fs"
	"slices"
)

// MaxLargestFiles is how many of the largest files Sizes lists.
const MaxLargestFiles = 50

// SizeEntry is one file or folder in a folder, with what it holds.
type SizeEntry struct {
	Name    string `json:"name"`
	Type    string `json:"type"`
	Size    int64  `json:"size"`
	Files   int    `json:"files"`
	Folders int    `json:"folders"`
}

// Sizes is what a folder holds, entry by entry, biggest first, and the
// largest files anywhere below it.
type Sizes struct {
	Path    string      `json:"path"`
	Size    int64       `json:"size"`
	Files   int         `json:"files"`
	Folders int         `json:"folders"`
	Entries []SizeEntry `json:"entries"`
	Largest []Hit       `json:"largest"`
	// Truncated is true when the folder held more than MaxUsageVisits
	// files and folders, so the counts are too low.
	Truncated bool `json:"truncated"`
}

// Sizes counts what folder p holds in one walk: the size of every file and
// folder in it, and its largest files. Sizes are the files' lengths, the
// trash included, as it counts towards the disk quota too. Links are
// counted, never followed.
func (h *Home) Sizes(p string) (Sizes, error) {
	if err := h.checkFolder(p); err != nil {
		return Sizes{}, err
	}

	result := Sizes{Path: p, Entries: []SizeEntry{}, Largest: []Hit{}}
	largest := &hitHeap{}
	visits := 0

	// count adds what path holds to entry and reports whether to go on.
	var count func(path string, info fs.FileInfo, entry *SizeEntry) bool

	count = func(path string, info fs.FileInfo, entry *SizeEntry) bool {
		visits++
		if visits > MaxUsageVisits {
			result.Truncated = true

			return false
		}

		if !info.IsDir() {
			entry.Files++

			if info.Mode().IsRegular() {
				entry.Size += info.Size()
				largest.add(Hit{Path: path, Type: TypeFile, Size: info.Size(), Modified: info.ModTime().Unix()})
			}

			return true
		}

		entry.Folders++

		names, err := h.names(path)
		if err != nil {
			// A folder the account cannot read is passed over.
			return true
		}

		for _, name := range names {
			child := join(path, name)

			childInfo, err := h.root.Lstat(child)
			if err != nil {
				continue
			}

			if !count(child, childInfo, entry) {
				return false
			}
		}

		return true
	}

	names, err := h.names(p)
	if err != nil {
		return Sizes{}, err
	}

	for _, name := range names {
		child := join(p, name)

		info, err := h.root.Lstat(child)
		if err != nil {
			continue
		}

		entry := SizeEntry{Name: name, Type: typeOf(info.Mode())}
		done := count(child, info, &entry)

		// The entry itself is not one of its own folders.
		if info.IsDir() {
			entry.Folders--
		}

		result.Entries = append(result.Entries, entry)
		result.Size += entry.Size
		result.Files += entry.Files
		result.Folders += entry.Folders

		if info.IsDir() {
			result.Folders++
		}

		if !done {
			break
		}
	}

	slices.SortStableFunc(result.Entries, func(a, b SizeEntry) int {
		return cmp.Or(cmp.Compare(b.Size, a.Size), cmp.Compare(a.Name, b.Name))
	})

	result.Largest = largest.sorted()

	return result, nil
}

// hitHeap keeps the MaxLargestFiles largest files seen, smallest on top.
type hitHeap []Hit

func (h hitHeap) Len() int           { return len(h) }
func (h hitHeap) Less(i, j int) bool { return h[i].Size < h[j].Size }
func (h hitHeap) Swap(i, j int)      { h[i], h[j] = h[j], h[i] }
func (h *hitHeap) Push(x any)        { *h = append(*h, x.(Hit)) }

func (h *hitHeap) Pop() any {
	old := *h
	last := old[len(old)-1]
	*h = old[:len(old)-1]

	return last
}

func (h *hitHeap) add(hit Hit) {
	if h.Len() < MaxLargestFiles {
		heap.Push(h, hit)

		return
	}

	if hit.Size > (*h)[0].Size {
		(*h)[0] = hit
		heap.Fix(h, 0)
	}
}

// sorted is the files, largest first.
func (h *hitHeap) sorted() []Hit {
	hits := slices.Clone(*h)

	slices.SortStableFunc(hits, func(a, b Hit) int {
		return cmp.Or(cmp.Compare(b.Size, a.Size), cmp.Compare(a.Path, b.Path))
	})

	if hits == nil {
		return []Hit{}
	}

	return hits
}
