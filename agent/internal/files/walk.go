package files

import (
	"io/fs"
	"strings"
	"unicode/utf8"
)

const (
	// MaxSearchResults and MaxSearchVisits bound one search: it stops at
	// that many matches, or after looking at that many files and folders.
	MaxSearchResults = 500
	MaxSearchVisits  = 200_000
	// MaxQueryBytes bounds the text searched for.
	MaxQueryBytes = 100
	// MaxUsageVisits bounds counting the size of folders.
	MaxUsageVisits = 1_000_000
)

// Hit is a file or folder a search found.
type Hit struct {
	Path     string `json:"path"`
	Type     string `json:"type"`
	Size     int64  `json:"size"`
	Modified int64  `json:"modified"`
}

// SearchResult is what a search found, and whether it stopped early.
type SearchResult struct {
	Path      string `json:"path"`
	Query     string `json:"query"`
	Hits      []Hit  `json:"hits"`
	Truncated bool   `json:"truncated"`
}

// CheckQuery checks the text a search looks for.
func CheckQuery(query string) error {
	if query == "" || len(query) > MaxQueryBytes || !utf8.ValidString(query) || strings.ContainsAny(query, "/\x00") {
		return errorf(ReasonInvalid, "search for 1 to %d characters, without a slash", MaxQueryBytes)
	}

	return nil
}

// Search finds the files and folders under folder p whose name contains
// query, ignoring case. Links are listed but never followed, and the
// trash is left out.
func (h *Home) Search(p, query string) (SearchResult, error) {
	if err := h.checkFolder(p); err != nil {
		return SearchResult{}, err
	}

	result := SearchResult{Path: p, Query: query, Hits: []Hit{}}
	needle := strings.ToLower(query)
	visits := 0

	var walk func(dir string) bool

	walk = func(dir string) bool {
		names, err := h.names(dir)
		if err != nil {
			// A folder the account cannot read is passed over.
			return true
		}

		for _, name := range names {
			child := join(dir, name)
			if inTrash(child) {
				continue
			}

			visits++
			if visits > MaxSearchVisits {
				result.Truncated = true

				return false
			}

			info, err := h.root.Lstat(child)
			if err != nil {
				continue
			}

			if strings.Contains(strings.ToLower(name), needle) {
				if len(result.Hits) == MaxSearchResults {
					result.Truncated = true

					return false
				}

				hit := Hit{Path: child, Type: typeOf(info.Mode()), Modified: info.ModTime().Unix()}
				if hit.Type == TypeFile {
					hit.Size = info.Size()
				}

				result.Hits = append(result.Hits, hit)
			}

			if info.IsDir() && !walk(child) {
				return false
			}
		}

		return true
	}

	walk(p)

	return result, nil
}

// Usage is how much a file or folder holds: the size of its files in
// bytes, and how many files and folders it has. Links are counted, not
// followed.
type Usage struct {
	Path      string `json:"path"`
	Size      int64  `json:"size"`
	Files     int    `json:"files"`
	Folders   int    `json:"folders"`
	Truncated bool   `json:"truncated"`
}

// UsageResult is the usage of every path asked for.
type UsageResult struct {
	Usage  []Usage   `json:"usage"`
	Failed []Failure `json:"failed"`
}

// DiskUsage counts what each path holds.
func (h *Home) DiskUsage(paths []string) UsageResult {
	result := UsageResult{Usage: []Usage{}, Failed: []Failure{}}

	for _, p := range paths {
		usage, err := h.usage(p)
		if err != nil {
			outcome := newOutcome()
			outcome.add(p, err)
			result.Failed = append(result.Failed, outcome.Failed...)

			continue
		}

		result.Usage = append(result.Usage, usage)
	}

	return result
}

func (h *Home) usage(p string) (Usage, error) {
	info, err := h.root.Lstat(rootName(p))
	if err != nil {
		return Usage{}, fail(err, p)
	}

	usage := Usage{Path: p}
	visits := 0

	var count func(path string, info fs.FileInfo) bool

	count = func(path string, info fs.FileInfo) bool {
		visits++
		if visits > MaxUsageVisits {
			usage.Truncated = true

			return false
		}

		switch {
		case info.IsDir():
			if path != p {
				usage.Folders++
			}

			names, err := h.names(path)
			if err != nil {
				return true
			}

			for _, name := range names {
				child := join(path, name)

				childInfo, err := h.root.Lstat(child)
				if err != nil {
					continue
				}

				if !count(child, childInfo) {
					return false
				}
			}
		case info.Mode().IsRegular():
			usage.Files++
			usage.Size += info.Size()
		default:
			usage.Files++
		}

		return true
	}

	count(p, info)

	return usage, nil
}
