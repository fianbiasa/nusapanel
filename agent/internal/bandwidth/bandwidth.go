// Package bandwidth counts the traffic of websites, mail and FTP from their
// logs: what websites sent (Nginx access logs), mail sent from and
// delivered to each domain (Postfix's log), and what each FTP login
// downloaded and uploaded (Pure-FTPd's transfer log).
//
// A count starts where the previous one stopped: the caller keeps the
// cursor this package returns and hands it back, so a count is never
// taken twice and the agent keeps no state. Logs rotated since are
// followed to their ".1" file by inode.
package bandwidth

import (
	"bufio"
	"bytes"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"syscall"
	"time"

	"rayxo/agent/internal/validate"
)

// Config says where the logs are.
type Config struct {
	// WebLogDir holds <domain>.access.log of every website.
	WebLogDir string
	// MailLog is Postfix's log.
	MailLog string
	// FTPLog is Pure-FTPd's transfer log, in common log format.
	FTPLog string
	// Budget is how many bytes of log one count reads at most; the rest
	// waits for the next count.
	Budget int64
	// Location is the time zone dates are counted in; nil is the server's.
	Location *time.Location
}

// DefaultConfig returns the logs' locations on a RAYXO server.
func DefaultConfig() Config {
	return Config{
		WebLogDir: "/var/log/rayxo/domains",
		MailLog:   "/var/log/mail.log",
		FTPLog:    "/var/log/pure-ftpd/transfer.log",
		Budget:    256 << 20,
	}
}

// Position is where a count stopped in one log: the file, by inode, and
// the offset after the last whole line read.
type Position struct {
	Inode  uint64 `json:"inode"`
	Offset int64  `json:"offset"`
}

// Cursor is where a count stopped in each log, by its path.
type Cursor map[string]Position

// DomainBytes is the traffic of one domain on one day.
type DomainBytes struct {
	Domain string `json:"domain"`
	Date   string `json:"date"`
	Bytes  int64  `json:"bytes"`
}

// UserBytes is the traffic of one FTP login on one day.
type UserBytes struct {
	User  string `json:"user"`
	Date  string `json:"date"`
	Bytes int64  `json:"bytes"`
}

// Report is one count: the traffic read since the cursor it started from,
// and the cursor to start the next one from. Complete is false when the
// budget ran out before the end of every log.
type Report struct {
	Web      []DomainBytes `json:"web"`
	Mail     []DomainBytes `json:"mail"`
	FTP      []UserBytes   `json:"ftp"`
	Cursor   Cursor        `json:"cursor"`
	Complete bool          `json:"complete"`
}

// Counter counts traffic from the logs in its Config.
type Counter struct {
	cfg Config
}

// New returns a Counter for cfg.
func New(cfg Config) *Counter {
	if cfg.Location == nil {
		cfg.Location = time.Local
	}

	if cfg.Budget <= 0 {
		cfg.Budget = DefaultConfig().Budget
	}

	return &Counter{cfg: cfg}
}

// maxLine is the longest log line counted; longer ones are skipped.
const maxLine = 64 << 10

// maxQueued bounds how many messages' sizes a count remembers between
// Postfix's queue manager line and the delivery lines that follow it.
const maxQueued = 200_000

// tally adds up bytes per name (a domain or a login) and date.
type tally map[string]map[string]int64

func (t tally) add(name, date string, bytes int64) {
	if bytes <= 0 {
		return
	}

	if t[name] == nil {
		t[name] = map[string]int64{}
	}

	t[name][date] += bytes
}

// Count reads every log from where cursor says the previous count stopped
// (from the start for a log the cursor does not know) and returns what
// it found and where it stopped. Entries of cursor for other files are
// ignored: only this server's logs are ever read.
func (c *Counter) Count(cursor Cursor) (Report, error) {
	budget := c.cfg.Budget
	next := Cursor{}
	complete := true
	web, mail, ftp := tally{}, tally{}, tally{}

	logs, err := filepath.Glob(filepath.Join(c.cfg.WebLogDir, "*.access.log"))
	if err != nil {
		return Report{}, err
	}

	sort.Strings(logs)

	for _, path := range logs {
		domain := strings.TrimSuffix(filepath.Base(path), ".access.log")
		if validate.Domain(domain) != nil {
			continue
		}

		done, err := c.follow(path, cursor, next, &budget, func(line []byte) {
			if date, bytes, ok := c.parseWeb(line); ok {
				web.add(domain, date, bytes)
			}
		})
		if err != nil {
			return Report{}, err
		}

		complete = complete && done
	}

	queued := map[string]queuedMessage{}

	done, err := c.follow(c.cfg.MailLog, cursor, next, &budget, func(line []byte) {
		if domain, date, bytes, ok := c.parseMail(line, queued); ok {
			mail.add(domain, date, bytes)
		}
	})
	if err != nil {
		return Report{}, err
	}

	complete = complete && done

	done, err = c.follow(c.cfg.FTPLog, cursor, next, &budget, func(line []byte) {
		if user, date, bytes, ok := c.parseFTP(line); ok {
			ftp.add(user, date, bytes)
		}
	})
	if err != nil {
		return Report{}, err
	}

	return Report{
		Web:      domainBytes(web),
		Mail:     domainBytes(mail),
		FTP:      userBytes(ftp),
		Cursor:   next,
		Complete: complete && done,
	}, nil
}

// follow reads the log at path from its position in cursor, following it
// to its rotated ".1" file when it was rotated since, records where it
// stopped in next, and reports whether it reached the end.
func (c *Counter) follow(path string, cursor, next Cursor, budget *int64, each func([]byte)) (bool, error) {
	info, err := os.Stat(path)
	if errors.Is(err, fs.ErrNotExist) {
		return true, nil
	}

	if err != nil {
		return false, err
	}

	current := inodeOf(info)
	previous, known := cursor[path]
	offset := int64(0)

	switch {
	case known && previous.Inode != current:
		rotated := path + ".1"

		if rotatedInfo, err := os.Stat(rotated); err == nil && inodeOf(rotatedInfo) == previous.Inode {
			stopped, done, err := readLines(rotated, previous.Offset, budget, each)
			if err != nil {
				return false, err
			}

			if !done {
				next[path] = Position{Inode: previous.Inode, Offset: stopped}

				return false, nil
			}
		}
	case known && info.Size() >= previous.Offset:
		offset = previous.Offset
	}

	stopped, done, err := readLines(path, offset, budget, each)
	if err != nil {
		return false, err
	}

	next[path] = Position{Inode: current, Offset: stopped}

	return done, nil
}

// readLines hands each whole line of the file from offset to each, until
// the end or until the budget is spent, and returns the offset after the
// last whole line read and whether it reached the end. A line without its
// newline yet is left for the next count.
func readLines(path string, offset int64, budget *int64, each func([]byte)) (int64, bool, error) {
	file, err := os.Open(path)
	if err != nil {
		if errors.Is(err, fs.ErrNotExist) {
			return offset, true, nil
		}

		return offset, false, err
	}
	defer file.Close()

	if _, err := file.Seek(offset, io.SeekStart); err != nil {
		return offset, false, fmt.Errorf("seek %s: %w", path, err)
	}

	reader := bufio.NewReaderSize(file, maxLine)
	position := offset

	for {
		if *budget <= 0 {
			return position, false, nil
		}

		line, length, err := readLine(reader)
		if errors.Is(err, io.EOF) {
			return position, true, nil
		}

		if err != nil {
			return position, false, fmt.Errorf("read %s: %w", path, err)
		}

		position += length
		*budget -= length

		if line != nil {
			each(line)
		}
	}
}

// readLine returns the next whole line without its newline, and how many
// bytes it took. A line longer than maxLine is skipped (nil line). io.EOF
// means there is no whole line left.
func readLine(reader *bufio.Reader) ([]byte, int64, error) {
	var length int64

	skipping := false

	for {
		chunk, err := reader.ReadSlice('\n')
		length += int64(len(chunk))

		switch {
		case err == nil:
			if skipping {
				return nil, length, nil
			}

			return bytes.TrimRight(chunk, "\r\n"), length, nil
		case errors.Is(err, bufio.ErrBufferFull):
			skipping = true
		default:
			// A partial last line: not consumed, so the next count reads
			// it again once it is whole.
			return nil, 0, err
		}
	}
}

// webLine is a line of Nginx's combined log format: the time it was
// served and the size of the body sent.
var webLine = regexp.MustCompile(`^\S+ \S+ \S+ \[([^\]]+)\] "[^"]*" \d{3} (\d+|-)`)

func (c *Counter) parseWeb(line []byte) (string, int64, bool) {
	match := webLine.FindSubmatch(line)
	if match == nil || string(match[2]) == "-" {
		return "", 0, false
	}

	date, ok := c.date("02/Jan/2006:15:04:05 -0700", string(match[1]))
	if !ok {
		return "", 0, false
	}

	bytes, err := strconv.ParseInt(string(match[2]), 10, 64)

	return date, bytes, err == nil
}

// queuedMessage is what Postfix's queue manager logged of a message.
type queuedMessage struct {
	sender string
	size   int64
}

var (
	mailLine  = regexp.MustCompile(`^(\S+) \S+ postfix/(qmgr|smtp|lmtp)\[\d+\]: ([0-9A-Za-z]+): (.*)$`)
	queueFrom = regexp.MustCompile(`^from=<([^>]*)>, size=(\d+), nrcpt=`)
	deliverTo = regexp.MustCompile(`^to=<([^>]+)>,.* status=sent\b`)
)

// parseMail counts a message once for every recipient it was delivered
// to: to the domain of the recipient for mail delivered to a mailbox
// here (lmtp), and to the domain of the sender for mail sent to another
// server (smtp). The sizes come from the queue manager's lines, which
// come first; queued remembers them.
func (c *Counter) parseMail(line []byte, queued map[string]queuedMessage) (string, string, int64, bool) {
	match := mailLine.FindSubmatch(line)
	if match == nil {
		return "", "", 0, false
	}

	id := string(match[3])
	rest := match[4]

	if string(match[2]) == "qmgr" {
		from := queueFrom.FindSubmatch(rest)
		if from == nil {
			return "", "", 0, false
		}

		size, err := strconv.ParseInt(string(from[2]), 10, 64)
		if err != nil {
			return "", "", 0, false
		}

		if len(queued) >= maxQueued {
			clear(queued)
		}

		queued[id] = queuedMessage{sender: string(from[1]), size: size}

		return "", "", 0, false
	}

	to := deliverTo.FindSubmatch(rest)
	message, ok := queued[id]

	if to == nil || !ok {
		return "", "", 0, false
	}

	address := string(to[1])
	if string(match[2]) == "smtp" {
		address = message.sender
	}

	domain := domainOf(address)
	if domain == "" {
		return "", "", 0, false
	}

	date, ok := c.date(time.RFC3339Nano, string(match[1]))
	if !ok {
		return "", "", 0, false
	}

	return domain, date, message.size, true
}

// ftpLine is a line of Pure-FTPd's transfer log in common log format: the
// login, the time and the size of the file sent or received.
var ftpLine = regexp.MustCompile(`^\S+ \S+ (\S+) \[([^\]]+)\] "[A-Z]+ [^"]*" \d{3} (\d+)`)

func (c *Counter) parseFTP(line []byte) (string, string, int64, bool) {
	match := ftpLine.FindSubmatch(line)
	if match == nil || string(match[1]) == "-" || len(match[1]) > 255 {
		return "", "", 0, false
	}

	date, ok := c.date("02/Jan/2006:15:04:05 -0700", string(match[2]))
	if !ok {
		return "", "", 0, false
	}

	bytes, err := strconv.ParseInt(string(match[3]), 10, 64)

	return string(match[1]), date, bytes, err == nil
}

// date returns the day value (in layout) falls on, in the counter's time
// zone, as YYYY-MM-DD.
func (c *Counter) date(layout, value string) (string, bool) {
	moment, err := time.Parse(layout, value)
	if err != nil {
		return "", false
	}

	return moment.In(c.cfg.Location).Format(time.DateOnly), true
}

// domainOf returns the lower-case domain of an email address, or "" when
// it has none that is valid.
func domainOf(address string) string {
	at := strings.LastIndexByte(address, '@')
	if at < 0 {
		return ""
	}

	domain := strings.ToLower(address[at+1:])
	if validate.Domain(domain) != nil {
		return ""
	}

	return domain
}

func inodeOf(info fs.FileInfo) uint64 {
	if stat, ok := info.Sys().(*syscall.Stat_t); ok {
		return uint64(stat.Ino)
	}

	return 0
}

func domainBytes(t tally) []DomainBytes {
	entries := []DomainBytes{}

	for _, name := range sortedKeys(t) {
		for _, date := range sortedKeys(t[name]) {
			entries = append(entries, DomainBytes{Domain: name, Date: date, Bytes: t[name][date]})
		}
	}

	return entries
}

func userBytes(t tally) []UserBytes {
	entries := []UserBytes{}

	for _, name := range sortedKeys(t) {
		for _, date := range sortedKeys(t[name]) {
			entries = append(entries, UserBytes{User: name, Date: date, Bytes: t[name][date]})
		}
	}

	return entries
}

func sortedKeys[V any](m map[string]V) []string {
	keys := make([]string, 0, len(m))
	for key := range m {
		keys = append(keys, key)
	}

	sort.Strings(keys)

	return keys
}
