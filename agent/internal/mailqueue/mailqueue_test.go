package mailqueue

import (
	"context"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
)

// The output of postqueue -j on a server: a message held in the maildrop
// by the send limit, and a deferred one with the reason.
const sample = `{"queue_name": "maildrop", "queue_id": "D659820AD2", "arrival_time": 1794417103, "message_size": 229, "forced_expire": false, "sender": "info@example.com", "recipients": [{"address": "other@example.com"}]}
{"queue_name": "deferred", "queue_id": "4C2E520ABA", "arrival_time": 1794417200, "message_size": 1830, "forced_expire": false, "sender": "news@example.com", "recipients": [{"address": "someone@example.org", "delay_reason": "connect to mx.example.org[192.0.2.1]:25: Connection timed out"}]}
`

type fakeRun struct {
	calls []string
}

func newQueue(t *testing.T) (*Queue, *fakeRun, string) {
	t.Helper()

	maildrop := t.TempDir()
	run := &fakeRun{}

	queue := New(Config{
		Maildrop: maildrop,
		Run: func(_ context.Context, program string, args ...string) ([]byte, error) {
			run.calls = append(run.calls, filepath.Base(program)+" "+strings.Join(args, " "))

			if program == postqueueProgram && slices.Equal(args, []string{"-j"}) {
				return []byte(sample), nil
			}

			return nil, nil
		},
	})

	return queue, run, maildrop
}

func TestListShowsTheNewestFirstWithWhyTheyWait(t *testing.T) {
	queue, _, _ := newQueue(t)

	listing, err := queue.List(context.Background(), 500)
	if err != nil {
		t.Fatal(err)
	}

	if listing.Total != 2 || len(listing.Messages) != 2 {
		t.Fatalf("listing: %+v", listing)
	}

	deferred := listing.Messages[0]
	if deferred.ID != "4C2E520ABA" || deferred.Queue != "deferred" || deferred.Size != 1830 || deferred.Sender != "news@example.com" {
		t.Fatalf("first: %+v", deferred)
	}

	if len(deferred.Recipients) != 1 || !strings.Contains(deferred.Recipients[0].Reason, "Connection timed out") {
		t.Fatalf("recipients: %+v", deferred.Recipients)
	}

	if listing.Messages[1].Queue != "maildrop" || listing.Messages[1].Recipients[0].Address != "other@example.com" {
		t.Fatalf("second: %+v", listing.Messages[1])
	}

	limited, err := queue.List(context.Background(), 1)
	if err != nil {
		t.Fatal(err)
	}

	if limited.Total != 2 || len(limited.Messages) != 1 {
		t.Fatalf("limited listing: %+v", limited)
	}
}

func TestRetryTriesOneOrEveryMessage(t *testing.T) {
	queue, run, _ := newQueue(t)

	if err := queue.Retry(context.Background(), "4C2E520ABA"); err != nil {
		t.Fatal(err)
	}

	if err := queue.Retry(context.Background(), ""); err != nil {
		t.Fatal(err)
	}

	if !slices.Equal(run.calls, []string{"postqueue -i 4C2E520ABA", "postqueue -f"}) {
		t.Fatalf("calls: %v", run.calls)
	}
}

func TestDeleteRemovesQueuedAndHeldMessages(t *testing.T) {
	queue, run, maildrop := newQueue(t)

	held := filepath.Join(maildrop, "D659820AD2")
	if err := os.WriteFile(held, []byte("message"), 0o744); err != nil {
		t.Fatal(err)
	}

	outcome, err := queue.Delete(context.Background(), []string{"4C2E520ABA", "D659820AD2", "ABCDEF1234"})
	if err != nil {
		t.Fatal(err)
	}

	if !slices.Equal(outcome.Done, []string{"4C2E520ABA", "D659820AD2"}) {
		t.Fatalf("done: %v", outcome.Done)
	}

	if len(outcome.Failed) != 1 || outcome.Failed[0].ID != "ABCDEF1234" {
		t.Fatalf("failed: %+v", outcome.Failed)
	}

	if !slices.Contains(run.calls, "postsuper -d 4C2E520ABA deferred") {
		t.Fatalf("calls: %v", run.calls)
	}

	if _, err := os.Stat(held); !os.IsNotExist(err) {
		t.Fatalf("the held message is still there: %v", err)
	}
}

func TestBadQueueIDsNeverReachPostfix(t *testing.T) {
	queue, run, _ := newQueue(t)
	ctx := context.Background()

	for _, id := range []string{"-f", "../../etc/passwd", "ABC", "4C2E520ABA deferred", "ALL"} {
		if err := queue.Retry(ctx, id); err == nil {
			t.Errorf("retry %q was accepted", id)
		}

		if _, err := queue.Delete(ctx, []string{id}); err == nil {
			t.Errorf("delete %q was accepted", id)
		}
	}

	if _, err := queue.Delete(ctx, nil); err == nil {
		t.Error("an empty delete was accepted")
	}

	if len(run.calls) != 0 {
		t.Fatalf("bad IDs reached Postfix: %v", run.calls)
	}
}
