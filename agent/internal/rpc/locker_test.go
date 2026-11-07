package rpc

import (
	"context"
	"errors"
	"testing"
	"time"
)

func TestLockerBlocksTheSameKeyUntilReleased(t *testing.T) {
	var locker keyedLocker

	unlock, err := locker.lock(context.Background(), "account:budi")
	if err != nil {
		t.Fatalf("first lock failed: %v", err)
	}

	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Millisecond)
	defer cancel()

	if _, err := locker.lock(ctx, "account:budi"); !errors.Is(err, context.DeadlineExceeded) {
		t.Fatalf("expected the second lock to time out, got %v", err)
	}

	unlock()

	unlockAgain, err := locker.lock(context.Background(), "account:budi")
	if err != nil {
		t.Fatalf("lock after release failed: %v", err)
	}

	unlockAgain()

	if len(locker.locks) != 0 {
		t.Fatalf("expected released keys to be forgotten, %d remain", len(locker.locks))
	}
}

func TestLockerDoesNotBlockDifferentKeys(t *testing.T) {
	var locker keyedLocker

	unlockBudi, err := locker.lock(context.Background(), "account:budi")
	if err != nil {
		t.Fatal(err)
	}
	defer unlockBudi()

	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Millisecond)
	defer cancel()

	unlockSari, err := locker.lock(ctx, "account:sari")
	if err != nil {
		t.Fatalf("a different key should not wait: %v", err)
	}

	unlockSari()
}
