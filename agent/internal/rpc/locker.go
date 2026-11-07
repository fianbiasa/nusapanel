package rpc

import (
	"context"
	"sync"
)

// keyedLocker serializes work per key (e.g. per hosting account) while letting
// different keys proceed in parallel. The zero value is ready to use.
type keyedLocker struct {
	mu    sync.Mutex
	locks map[string]*keyLock
}

type keyLock struct {
	token chan struct{}
	refs  int
}

// lock waits until key is free or ctx is done. The returned function releases
// the lock and must be called exactly once.
func (k *keyedLocker) lock(ctx context.Context, key string) (func(), error) {
	k.mu.Lock()
	if k.locks == nil {
		k.locks = map[string]*keyLock{}
	}

	entry, ok := k.locks[key]
	if !ok {
		entry = &keyLock{token: make(chan struct{}, 1)}
		k.locks[key] = entry
	}

	entry.refs++
	k.mu.Unlock()

	select {
	case entry.token <- struct{}{}:
		return func() {
			<-entry.token
			k.release(key, entry)
		}, nil
	case <-ctx.Done():
		k.release(key, entry)

		return nil, ctx.Err()
	}
}

func (k *keyedLocker) release(key string, entry *keyLock) {
	k.mu.Lock()
	defer k.mu.Unlock()

	entry.refs--
	if entry.refs == 0 {
		delete(k.locks, key)
	}
}
