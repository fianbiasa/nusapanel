//go:build unix

package files

import (
	"errors"
	"io"
	"path/filepath"
	"syscall"
	"testing"
	"time"
)

// A FIFO with no writer makes a plain open(2) wait forever, which would
// hold the agent's call (and one of its slots) until it timed out. Every
// operation that reads must give up on it at once.
func TestOperationsDoNotWaitOnAFIFO(t *testing.T) {
	h, home, _ := newHome(t)

	if err := syscall.Mkfifo(filepath.Join(home, "pipe"), 0o644); err != nil {
		t.Fatal(err)
	}

	operations := map[string]func() error{
		"list": func() error { _, err := h.List("pipe"); return err },
		"read": func() error { _, err := h.Read("pipe"); return err },
		"download": func() error {
			_, err := h.Download(io.Discard, "pipe")
			return err
		},
		"extract": func() error { _, err := h.Extract("pipe", "", false); return err },
		"compress": func() error {
			_, err := h.Compress([]string{"pipe"}, "", FormatZip)
			return err
		},
		"copy": func() error {
			if outcome := h.Copy([]string{"pipe"}, "copies"); len(outcome.Failed) > 0 {
				return errors.New(outcome.Failed[0].Message)
			}

			return nil
		},
	}

	for name, operation := range operations {
		done := make(chan error, 1)

		go func() { done <- operation() }()

		select {
		case err := <-done:
			if err == nil {
				t.Errorf("%s: a FIFO was taken", name)
			}
		case <-time.After(5 * time.Second):
			t.Fatalf("%s: still waiting on the FIFO after 5 seconds", name)
		}
	}
}
