// Package mailqueue shows Postfix's mail queue and acts on it: try messages
// again now, or delete them. Messages held back by the daily send limit
// wait in the maildrop, where Postfix's pickup tries them again by itself.
package mailqueue

import (
	"bytes"
	"cmp"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strings"

	"rayxo/agent/internal/runner"
)

const (
	postqueueProgram = "/usr/sbin/postqueue"
	postsuperProgram = "/usr/sbin/postsuper"

	// MaxListed is the most messages one listing returns.
	MaxListed = 2000
	// MaxDeleted is the most messages one call deletes.
	MaxDeleted = 500
)

// idPattern matches Postfix queue IDs, short (hex) or long.
var idPattern = regexp.MustCompile(`^[0-9A-Za-z]{5,32}$`)

// ValidateID returns an error unless id looks like a Postfix queue ID.
func ValidateID(id string) error {
	if !idPattern.MatchString(id) {
		return fmt.Errorf("%q is not a queue ID", id)
	}

	return nil
}

// Message is one message in the queue.
type Message struct {
	// Queue is where it waits: maildrop (submitted locally, not picked up
	// yet, such as held by the send limit), incoming, active (being sent),
	// deferred (to be tried again) or hold.
	Queue      string      `json:"queue"`
	ID         string      `json:"id"`
	ArrivedAt  int64       `json:"arrived_at"`
	Size       int64       `json:"size"`
	Sender     string      `json:"sender"`
	Recipients []Recipient `json:"recipients"`
}

// Recipient is a recipient of a queued message, with the reason its
// delivery waits when Postfix gave one.
type Recipient struct {
	Address string `json:"address"`
	Reason  string `json:"reason,omitempty"`
}

// Listing is the queue, newest first.
type Listing struct {
	// Total is how many messages are queued; Messages holds at most the
	// limit asked for.
	Total    int       `json:"total"`
	Messages []Message `json:"messages"`
}

// Outcome is what happened to the messages a call was given.
type Outcome struct {
	Done   []string `json:"done"`
	Failed []Failed `json:"failed"`
}

// Failed is a message a call could not act on, and why.
type Failed struct {
	ID      string `json:"id"`
	Message string `json:"message"`
}

// Config is where the queue lives and how commands run.
type Config struct {
	// Maildrop is Postfix's maildrop folder.
	Maildrop string
	// Run runs a program; nil uses runner.Run.
	Run func(ctx context.Context, program string, args ...string) ([]byte, error)
}

// DefaultConfig returns Postfix's locations on Ubuntu.
func DefaultConfig() Config {
	return Config{Maildrop: "/var/spool/postfix/maildrop"}
}

// Queue acts on the mail queue.
type Queue struct {
	cfg Config
}

// New returns a Queue for cfg.
func New(cfg Config) *Queue {
	if cfg.Run == nil {
		cfg.Run = runner.Run
	}

	return &Queue{cfg: cfg}
}

// List returns the queue, newest first, at most limit messages.
func (q *Queue) List(ctx context.Context, limit int) (Listing, error) {
	messages, err := q.all(ctx)
	if err != nil {
		return Listing{}, err
	}

	slices.SortStableFunc(messages, func(a, b Message) int {
		return cmp.Or(cmp.Compare(b.ArrivedAt, a.ArrivedAt), strings.Compare(a.ID, b.ID))
	})

	listing := Listing{Total: len(messages), Messages: messages}
	if limit > 0 && len(messages) > limit {
		listing.Messages = messages[:limit]
	}

	return listing, nil
}

// Retry has Postfix try the message with id again now, or every deferred
// message when id is empty.
func (q *Queue) Retry(ctx context.Context, id string) error {
	if id == "" {
		_, err := q.cfg.Run(ctx, postqueueProgram, "-f")

		return err
	}

	if err := ValidateID(id); err != nil {
		return err
	}

	_, err := q.cfg.Run(ctx, postqueueProgram, "-i", id)

	return err
}

// Delete removes the messages with ids from the queue for good. Messages in
// the maildrop are files Postfix's postsuper does not handle; they are
// removed directly.
func (q *Queue) Delete(ctx context.Context, ids []string) (Outcome, error) {
	if len(ids) == 0 || len(ids) > MaxDeleted {
		return Outcome{}, fmt.Errorf("give 1 to %d queue IDs", MaxDeleted)
	}

	for _, id := range ids {
		if err := ValidateID(id); err != nil {
			return Outcome{}, err
		}
	}

	messages, err := q.all(ctx)
	if err != nil {
		return Outcome{}, err
	}

	queues := map[string]string{}
	for _, message := range messages {
		queues[message.ID] = message.Queue
	}

	outcome := Outcome{Done: []string{}, Failed: []Failed{}}

	for _, id := range ids {
		queue, ok := queues[id]

		switch {
		case !ok:
			outcome.Failed = append(outcome.Failed, Failed{ID: id, Message: "not in the queue (sent or removed already)"})
		case queue == "maildrop":
			err = q.removeMaildropFile(id)
		default:
			_, err = q.cfg.Run(ctx, postsuperProgram, "-d", id, queue)
		}

		if !ok {
			continue
		}

		if err != nil {
			outcome.Failed = append(outcome.Failed, Failed{ID: id, Message: err.Error()})
		} else {
			outcome.Done = append(outcome.Done, id)
		}
	}

	return outcome, nil
}

// removeMaildropFile deletes the maildrop file of id, which must be a
// regular file right in the maildrop.
func (q *Queue) removeMaildropFile(id string) error {
	path := filepath.Join(q.cfg.Maildrop, id)

	info, err := os.Lstat(path)
	if err != nil {
		return err
	}

	if !info.Mode().IsRegular() {
		return fmt.Errorf("%s is not a regular file", path)
	}

	return os.Remove(path)
}

// all returns every queued message, as postqueue -j reports them.
func (q *Queue) all(ctx context.Context) ([]Message, error) {
	output, err := q.cfg.Run(ctx, postqueueProgram, "-j")
	if err != nil {
		return nil, err
	}

	return parse(output)
}

// postqueueMessage is a line of postqueue -j.
type postqueueMessage struct {
	QueueName   string `json:"queue_name"`
	QueueID     string `json:"queue_id"`
	ArrivalTime int64  `json:"arrival_time"`
	MessageSize int64  `json:"message_size"`
	Sender      string `json:"sender"`
	Recipients  []struct {
		Address     string `json:"address"`
		DelayReason string `json:"delay_reason"`
	} `json:"recipients"`
}

// parse reads the JSON objects postqueue -j prints, one per line.
func parse(output []byte) ([]Message, error) {
	decoder := json.NewDecoder(bytes.NewReader(output))
	messages := []Message{}

	for {
		var line postqueueMessage

		err := decoder.Decode(&line)
		if errors.Is(err, io.EOF) {
			return messages, nil
		}

		if err != nil {
			return nil, fmt.Errorf("unreadable postqueue output: %w", err)
		}

		message := Message{
			Queue:      line.QueueName,
			ID:         line.QueueID,
			ArrivedAt:  line.ArrivalTime,
			Size:       line.MessageSize,
			Sender:     line.Sender,
			Recipients: []Recipient{},
		}

		for _, recipient := range line.Recipients {
			message.Recipients = append(message.Recipients, Recipient{Address: recipient.Address, Reason: recipient.DelayReason})
		}

		messages = append(messages, message)
	}
}
