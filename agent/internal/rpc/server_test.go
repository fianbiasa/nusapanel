package rpc_test

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"rayxo/agent/internal/client"
	"rayxo/agent/internal/rpc"
)

func TestCallReturnsTheResult(t *testing.T) {
	socketPath := startServer(t, nil)

	result, err := call(t, socketPath, "test.echo", map[string]string{"message": "halo"})
	if err != nil {
		t.Fatalf("call failed: %v", err)
	}

	if string(result) != `{"message":"halo"}` {
		t.Fatalf("unexpected result %s", result)
	}
}

func TestUnknownMethodsAreRejected(t *testing.T) {
	socketPath := startServer(t, nil)

	_, err := call(t, socketPath, "system.exec", nil)

	assertCode(t, err, rpc.CodeMethodNotFound)
}

func TestUnknownParamsAreRejected(t *testing.T) {
	socketPath := startServer(t, nil)

	_, err := call(t, socketPath, "test.echo", map[string]any{"message": "halo", "command": "rm -rf /"})

	assertCode(t, err, rpc.CodeInvalidParams)
}

func TestActionErrorsReachTheCaller(t *testing.T) {
	socketPath := startServer(t, nil)

	_, err := call(t, socketPath, "test.fail", nil)

	agentErr := assertCode(t, err, rpc.CodeActionFailed)
	if agentErr.Message != "domain already exists" {
		t.Fatalf("unexpected message %q", agentErr.Message)
	}
}

func TestInternalErrorsAreNotLeaked(t *testing.T) {
	socketPath := startServer(t, nil)

	_, err := call(t, socketPath, "test.crash", nil)

	agentErr := assertCode(t, err, rpc.CodeInternalError)
	if strings.Contains(agentErr.Message, "/etc/shadow") {
		t.Fatalf("internal error leaked details: %q", agentErr.Message)
	}
}

func TestPanicsAreRecovered(t *testing.T) {
	socketPath := startServer(t, nil)

	_, err := call(t, socketPath, "test.panic", nil)
	assertCode(t, err, rpc.CodeInternalError)

	if _, err := call(t, socketPath, "test.echo", map[string]string{"message": "still alive"}); err != nil {
		t.Fatalf("server stopped answering after a panic: %v", err)
	}
}

func TestCallersThatAreNotAllowedAreRejected(t *testing.T) {
	socketPath := startServer(t, func(server *rpc.Server) {
		server.Authorize = func(uint32) bool { return false }
	})

	_, err := call(t, socketPath, "test.echo", map[string]string{"message": "halo"})

	assertCode(t, err, rpc.CodeForbidden)
}

func TestInvalidJSONIsRejected(t *testing.T) {
	socketPath := startServer(t, nil)

	response := exchange(t, socketPath, "this is not json\n")

	if response.Error == nil || response.Error.Code != rpc.CodeParseError {
		t.Fatalf("expected parse error, got %+v", response.Error)
	}

	if string(response.ID) != "null" {
		t.Fatalf("expected null id, got %s", response.ID)
	}
}

func TestOversizedRequestsAreRejected(t *testing.T) {
	socketPath := startServer(t, func(server *rpc.Server) {
		server.MaxRequestBytes = 64
	})

	payload := `{"jsonrpc":"2.0","id":"1","method":"test.echo","params":{"message":"` +
		strings.Repeat("x", 200) + `"}}` + "\n"

	response := exchange(t, socketPath, payload)

	if response.Error == nil || response.Error.Code != rpc.CodeInvalidRequest {
		t.Fatalf("expected invalid request, got %+v", response.Error)
	}
}

func TestSocketIsCreatedWithTheRequestedMode(t *testing.T) {
	socketPath := filepath.Join(shortTempDir(t), "agent.sock")

	listener, err := rpc.Listen(socketPath, 0o660, -1)
	if err != nil {
		t.Fatalf("listen failed: %v", err)
	}
	defer listener.Close()

	info, err := os.Stat(socketPath)
	if err != nil {
		t.Fatalf("stat failed: %v", err)
	}

	if info.Mode().Type() != os.ModeSocket || info.Mode().Perm() != 0o660 {
		t.Fatalf("unexpected socket mode %v", info.Mode())
	}
}

func TestListenRefusesToReplaceARegularFile(t *testing.T) {
	path := filepath.Join(shortTempDir(t), "agent.sock")
	if err := os.WriteFile(path, []byte("important"), 0o600); err != nil {
		t.Fatal(err)
	}

	if _, err := rpc.Listen(path, 0o660, -1); err == nil {
		t.Fatal("expected an error for a regular file at the socket path")
	}

	if content, _ := os.ReadFile(path); string(content) != "important" {
		t.Fatal("the existing file was modified")
	}
}

func TestCallsForTheSameAccountRunOneAtATime(t *testing.T) {
	var active, peak atomic.Int32

	socketPath := startServer(t, func(server *rpc.Server) {
		server.Registry.Register("test.slow", slowAccountMethod(&active, &peak, 50*time.Millisecond))
	})

	runConcurrently(t, socketPath, []string{"budi", "budi", "budi"})

	if peak.Load() != 1 {
		t.Fatalf("expected calls for one account to be serialized, saw %d at once", peak.Load())
	}
}

func TestCallsForDifferentAccountsRunInParallel(t *testing.T) {
	var active, peak atomic.Int32

	socketPath := startServer(t, func(server *rpc.Server) {
		server.Registry.Register("test.slow", slowAccountMethod(&active, &peak, 100*time.Millisecond))
	})

	runConcurrently(t, socketPath, []string{"budi", "sari", "andi"})

	if peak.Load() < 2 {
		t.Fatalf("expected calls for different accounts to overlap, peak was %d", peak.Load())
	}
}

func TestCallsGiveUpWhenTheAccountStaysBusy(t *testing.T) {
	var active, peak atomic.Int32

	socketPath := startServer(t, func(server *rpc.Server) {
		server.CallTimeout = 100 * time.Millisecond
		server.Registry.Register("test.slow", slowAccountMethod(&active, &peak, 400*time.Millisecond))
	})

	first := make(chan error, 1)
	go func() {
		_, err := call(t, socketPath, "test.slow", map[string]string{"account": "budi"})
		first <- err
	}()

	time.Sleep(30 * time.Millisecond)

	_, err := call(t, socketPath, "test.slow", map[string]string{"account": "budi"})
	assertCode(t, err, rpc.CodeBusy)

	if err := <-first; err != nil {
		t.Fatalf("the first call should still succeed: %v", err)
	}
}

// startServer runs a server with test methods on a fresh socket and stops it
// when the test ends. By default only the current user may call it.
func startServer(t *testing.T, configure func(*rpc.Server)) string {
	t.Helper()

	socketPath := filepath.Join(shortTempDir(t), "agent.sock")

	listener, err := rpc.Listen(socketPath, 0o600, -1)
	if err != nil {
		t.Fatalf("listen failed: %v", err)
	}

	registry := rpc.NewRegistry()
	registry.Register("test.echo", rpc.Method{Handler: func(_ context.Context, params json.RawMessage) (any, error) {
		var input struct {
			Message string `json:"message"`
		}
		if err := rpc.DecodeParams(params, &input); err != nil {
			return nil, err
		}

		return map[string]string{"message": input.Message}, nil
	}})
	registry.Register("test.fail", rpc.Method{Handler: func(context.Context, json.RawMessage) (any, error) {
		return nil, rpc.Errorf(rpc.CodeActionFailed, "domain already exists")
	}})
	registry.Register("test.crash", rpc.Method{Handler: func(context.Context, json.RawMessage) (any, error) {
		return nil, errors.New("open /etc/shadow: permission denied")
	}})
	registry.Register("test.panic", rpc.Method{Handler: func(context.Context, json.RawMessage) (any, error) {
		panic("boom")
	}})

	server := &rpc.Server{
		Registry:  registry,
		Logger:    slog.New(slog.NewTextHandler(io.Discard, nil)),
		Authorize: func(uid uint32) bool { return uid == uint32(os.Getuid()) },
	}

	if configure != nil {
		configure(server)
	}

	ctx, cancel := context.WithCancel(context.Background())
	stopped := make(chan error, 1)

	go func() { stopped <- server.Serve(ctx, listener) }()

	t.Cleanup(func() {
		cancel()

		if err := <-stopped; err != nil {
			t.Errorf("serve returned an error: %v", err)
		}
	})

	return socketPath
}

// shortTempDir avoids t.TempDir, whose long paths can exceed the 104-byte
// limit for Unix socket paths on macOS.
func shortTempDir(t *testing.T) string {
	t.Helper()

	dir, err := os.MkdirTemp("", "rayxod")
	if err != nil {
		t.Fatal(err)
	}

	t.Cleanup(func() { _ = os.RemoveAll(dir) })

	return dir
}

func call(t *testing.T, socketPath, method string, params any) (json.RawMessage, error) {
	t.Helper()

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	return client.Call(ctx, socketPath, method, params)
}

type rawResponse struct {
	ID    json.RawMessage `json:"id"`
	Error *rpc.Error      `json:"error"`
}

// exchange sends a raw payload and decodes the single response line.
func exchange(t *testing.T, socketPath, payload string) rawResponse {
	t.Helper()

	conn, err := net.Dial("unix", socketPath)
	if err != nil {
		t.Fatalf("dial failed: %v", err)
	}
	defer conn.Close()

	_ = conn.SetDeadline(time.Now().Add(5 * time.Second))

	if _, err := conn.Write([]byte(payload)); err != nil {
		t.Fatalf("write failed: %v", err)
	}

	line, err := bufio.NewReader(conn).ReadBytes('\n')
	if err != nil {
		t.Fatalf("read failed: %v", err)
	}

	var response rawResponse
	if err := json.Unmarshal(line, &response); err != nil {
		t.Fatalf("invalid response %q: %v", line, err)
	}

	return response
}

func assertCode(t *testing.T, err error, code int) *rpc.Error {
	t.Helper()

	var agentErr *rpc.Error
	if !errors.As(err, &agentErr) {
		t.Fatalf("expected agent error %d, got %v", code, err)
	}

	if agentErr.Code != code {
		t.Fatalf("expected code %d, got %d (%s)", code, agentErr.Code, agentErr.Message)
	}

	return agentErr
}

// slowAccountMethod locks on the "account" param and records how many calls
// run at the same time.
func slowAccountMethod(active, peak *atomic.Int32, duration time.Duration) rpc.Method {
	return rpc.Method{
		LockKey: func(params json.RawMessage) (string, error) {
			var input struct {
				Account string `json:"account"`
			}
			if err := rpc.DecodeParams(params, &input); err != nil {
				return "", err
			}

			return "account:" + input.Account, nil
		},
		Handler: func(context.Context, json.RawMessage) (any, error) {
			now := active.Add(1)
			for {
				seen := peak.Load()
				if now <= seen || peak.CompareAndSwap(seen, now) {
					break
				}
			}

			time.Sleep(duration)
			active.Add(-1)

			return nil, nil
		},
	}
}

func runConcurrently(t *testing.T, socketPath string, accounts []string) {
	t.Helper()

	var wg sync.WaitGroup
	errs := make(chan error, len(accounts))

	for _, account := range accounts {
		wg.Add(1)

		go func() {
			defer wg.Done()

			if _, err := call(t, socketPath, "test.slow", map[string]string{"account": account}); err != nil {
				errs <- err
			}
		}()
	}

	wg.Wait()
	close(errs)

	for err := range errs {
		t.Fatalf("call failed: %v", err)
	}
}
