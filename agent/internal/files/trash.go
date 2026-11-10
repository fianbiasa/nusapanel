package files

import (
	"encoding/json"
	"errors"
	"io/fs"
	"path"
	"regexp"
	"slices"
	"strings"
	"time"
)

// trashIDPattern is the name of one deleted item's folder in the trash:
// when it was deleted (UTC) and a random part.
var trashIDPattern = regexp.MustCompile(`^[0-9]{8}-[0-9]{6}-[0-9a-f]{8}$`)

// CheckTrashID checks the id of a deleted item.
func CheckTrashID(id string) error {
	if !trashIDPattern.MatchString(id) {
		return errorf(ReasonInvalid, "%q is not the id of a deleted item", id)
	}

	return nil
}

// TrashItem is a deleted file or folder. The item itself is
// .trash/<id>/<name>; where it came from is noted in .trash/<id>.json.
type TrashItem struct {
	ID        string    `json:"id"`
	Name      string    `json:"name"`
	Path      string    `json:"path"`
	Type      string    `json:"type"`
	Size      int64     `json:"size"`
	DeletedAt time.Time `json:"deleted_at"`
}

// trashNote is the content of .trash/<id>.json.
type trashNote struct {
	Path      string    `json:"path"`
	Name      string    `json:"name"`
	Type      string    `json:"type"`
	Size      int64     `json:"size"`
	DeletedAt time.Time `json:"deleted_at"`
}

// Delete moves every path to the trash.
func (h *Home) Delete(paths []string) Outcome {
	outcome := newOutcome()

	for _, p := range paths {
		outcome.add(p, h.trash(p))
	}

	return outcome
}

func (h *Home) trash(p string) error {
	if err := h.checkSource(p); err != nil {
		return err
	}

	info, err := h.root.Lstat(p)
	if err != nil {
		return fail(err, p)
	}

	if err := h.root.Mkdir(TrashDir, 0o700); err != nil && !errors.Is(err, fs.ErrExist) {
		return fail(err, TrashDir)
	}

	deletedAt := h.now().UTC().Truncate(time.Second)
	id := deletedAt.Format("20060102-150405") + "-" + randomHex(4)
	folder := join(TrashDir, id)

	if err := h.root.Mkdir(folder, 0o700); err != nil {
		return fail(err, TrashDir)
	}

	size := info.Size()
	if info.IsDir() {
		size = 0
	}

	note, _ := json.Marshal(trashNote{
		Path:      p,
		Name:      path.Base(p),
		Type:      typeOf(info.Mode()),
		Size:      size,
		DeletedAt: deletedAt,
	})

	err = h.root.WriteFile(folder+".json", note, 0o600)
	if err == nil {
		err = h.root.Rename(p, join(folder, path.Base(p)))
	}

	if err != nil {
		_ = h.root.Remove(folder)
		_ = h.root.Remove(folder + ".json")

		return fail(err, p)
	}

	return nil
}

// TrashList returns the deleted items, newest first.
func (h *Home) TrashList() ([]TrashItem, error) {
	names, err := h.names(TrashDir)

	var notFound *Error
	if errors.As(err, &notFound) && notFound.Reason == ReasonNotFound {
		return []TrashItem{}, nil
	}

	if err != nil {
		return nil, err
	}

	items := []TrashItem{}

	for _, id := range names {
		if !trashIDPattern.MatchString(id) {
			continue
		}

		item, err := h.trashItem(id)
		if err != nil {
			continue
		}

		items = append(items, item)
	}

	slices.SortFunc(items, func(a, b TrashItem) int {
		return b.DeletedAt.Compare(a.DeletedAt)
	})

	if len(items) > MaxEntries {
		items = items[:MaxEntries]
	}

	return items, nil
}

// trashItem reads deleted item id. Without its note, it still lists the
// item, with an unknown origin.
func (h *Home) trashItem(id string) (TrashItem, error) {
	folder := join(TrashDir, id)

	names, err := h.names(folder)
	if err != nil {
		return TrashItem{}, err
	}

	if len(names) != 1 {
		return TrashItem{}, errorf(ReasonInvalid, "deleted item %s is damaged", id)
	}

	item := TrashItem{ID: id, Name: names[0], Type: TypeOther}

	if info, err := h.root.Lstat(join(folder, names[0])); err == nil {
		item.Type = typeOf(info.Mode())
		item.DeletedAt = info.ModTime().UTC()

		if !info.IsDir() {
			item.Size = info.Size()
		}
	}

	var note trashNote
	if data, err := h.root.ReadFile(folder + ".json"); err == nil && json.Unmarshal(data, &note) == nil &&
		note.Name == names[0] && CheckPath(note.Path) == nil {
		item.Path = note.Path
		item.DeletedAt = note.DeletedAt
	}

	return item, nil
}

// Restore puts every deleted item back where it was, creating its folder
// when it is gone. An item whose place is taken stays in the trash.
func (h *Home) Restore(ids []string) Outcome {
	outcome := newOutcome()

	for _, id := range ids {
		outcome.add(id, h.restore(id))
	}

	return outcome
}

func (h *Home) restore(id string) error {
	if err := CheckTrashID(id); err != nil {
		return err
	}

	item, err := h.trashItem(id)
	if err != nil {
		return err
	}

	if item.Path == "" || inTrash(item.Path) {
		return errorf(ReasonInvalid, "where %s came from is unknown; move it out of the trash folder with FTP", item.Name)
	}

	if err := h.free(item.Path); err != nil {
		return errorf(ReasonExists, "%s already exists; rename or move it first", item.Path)
	}

	if dir := parent(item.Path); dir != "" {
		if err := h.root.MkdirAll(dir, newDirMode); err != nil {
			return fail(err, dir)
		}
	}

	folder := join(TrashDir, id)

	if err := h.root.Rename(join(folder, item.Name), item.Path); err != nil {
		return fail(err, item.Path)
	}

	_ = h.root.Remove(folder)
	_ = h.root.Remove(folder + ".json")

	return nil
}

// Purge removes deleted items for good.
func (h *Home) Purge(ids []string) Outcome {
	outcome := newOutcome()

	for _, id := range ids {
		outcome.add(id, h.purge(id))
	}

	return outcome
}

func (h *Home) purge(id string) error {
	if err := CheckTrashID(id); err != nil {
		return err
	}

	folder := join(TrashDir, id)

	if _, err := h.root.Lstat(folder); err != nil {
		return fail(err, folder)
	}

	if err := h.removeAll(folder); err != nil {
		return err
	}

	_ = h.root.Remove(folder + ".json")

	return nil
}

// EmptyTrash removes everything in the trash for good.
func (h *Home) EmptyTrash() Outcome {
	outcome := newOutcome()

	names, err := h.names(TrashDir)

	var notFound *Error
	if errors.As(err, &notFound) && notFound.Reason == ReasonNotFound {
		return outcome
	}

	if err != nil {
		outcome.add(TrashDir, err)

		return outcome
	}

	for _, name := range names {
		err := h.removeAll(join(TrashDir, name))

		// The note of an item goes with it; it is not an item of its own.
		if id, isNote := strings.CutSuffix(name, ".json"); isNote && trashIDPattern.MatchString(id) {
			continue
		}

		outcome.add(name, err)
	}

	return outcome
}

// removeAll removes p and everything in it. Folders the account made
// read-only are made writable first, as the owner may.
func (h *Home) removeAll(p string) error {
	if err := h.root.RemoveAll(p); err == nil {
		return nil
	}

	h.makeWritable(p)

	return fail(h.root.RemoveAll(p), p)
}

func (h *Home) makeWritable(p string) {
	info, err := h.root.Lstat(p)
	if err != nil || !info.IsDir() {
		return
	}

	_ = h.root.Chmod(p, 0o700)

	names, err := h.names(p)
	if err != nil {
		return
	}

	for _, name := range names {
		h.makeWritable(join(p, name))
	}
}
