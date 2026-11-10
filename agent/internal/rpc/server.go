package rpc

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"runtime/debug"
	"sync"
	"time"

	"rayxo/agent/internal/peercred"
)

// Server answers JSON-RPC requests on a Unix socket.
type Server struct {
	Registry *Registry

	// Authorize reports whether a caller with the given UID may use the
	// agent. It is required: a server without it refuses to start.
	Authorize func(uid uint32) bool

	// PeerUID returns the UID of the process on the other end of conn.
	// Defaults to the kernel-reported peer credentials.
	PeerUID func(conn *net.UnixConn) (uint32, error)

	Logger *slog.Logger

	// MaxRequestBytes limits one request line (default 1 MiB).
	MaxRequestBytes int
	// ReadTimeout bounds how long a caller may take to send its request (default 10s).
	ReadTimeout time.Duration
	// CallTimeout bounds one call, including waiting for its account lock (default 10m).
	CallTimeout time.Duration
	// MaxConcurrent limits calls handled at the same time (default 64).
	MaxConcurrent int

	locks    keyedLocker
	inFlight sync.WaitGroup
}

// Serve accepts connections until ctx is cancelled, then stops accepting and
// waits for in-flight calls to finish. Shutdown never cancels a running call,
// so an action is not interrupted halfway; CallTimeout still applies.
func (s *Server) Serve(ctx context.Context, listener *net.UnixListener) error {
	if s.Registry == nil || s.Authorize == nil {
		return errors.New("rpc: server needs a registry and an authorize function")
	}

	s.applyDefaults()

	go func() {
		<-ctx.Done()
		_ = listener.Close()
	}()

	slots := make(chan struct{}, s.MaxConcurrent)

	for {
		conn, err := listener.AcceptUnix()
		if err != nil {
			if ctx.Err() != nil {
				s.inFlight.Wait()

				return nil
			}

			s.Logger.Error("accept failed", "error", err)
			time.Sleep(100 * time.Millisecond)

			continue
		}

		slots <- struct{}{}
		s.inFlight.Add(1)

		go func() {
			defer func() {
				<-slots
				s.inFlight.Done()
			}()

			s.handle(ctx, conn)
		}()
	}
}

func (s *Server) applyDefaults() {
	if s.PeerUID == nil {
		s.PeerUID = peercred.UID
	}

	if s.Logger == nil {
		s.Logger = slog.Default()
	}

	if s.MaxRequestBytes <= 0 {
		s.MaxRequestBytes = 1 << 20
	}

	if s.ReadTimeout <= 0 {
		s.ReadTimeout = 10 * time.Second
	}

	if s.CallTimeout <= 0 {
		s.CallTimeout = 10 * time.Minute
	}

	if s.MaxConcurrent <= 0 {
		s.MaxConcurrent = 64
	}
}

func (s *Server) handle(ctx context.Context, conn *net.UnixConn) {
	defer conn.Close()

	uid, err := s.PeerUID(conn)
	if err != nil {
		s.Logger.Warn("rejected caller: unknown peer credentials", "error", err)
		s.write(conn, Response{Error: Errorf(CodeForbidden, "the agent could not identify the calling process")})

		return
	}

	if !s.Authorize(uid) {
		s.Logger.Warn("rejected caller", "uid", uid)
		s.write(conn, Response{Error: Errorf(CodeForbidden, "this process is not allowed to use the agent")})

		return
	}

	_ = conn.SetReadDeadline(time.Now().Add(s.ReadTimeout))

	request, requestErr := s.readRequest(conn)
	if requestErr != nil {
		s.write(conn, Response{ID: request.ID, Error: requestErr})

		return
	}

	_ = conn.SetReadDeadline(time.Time{})

	s.write(conn, s.call(ctx, uid, request))
}

func (s *Server) readRequest(conn io.Reader) (Request, *Error) {
	reader := bufio.NewReader(io.LimitReader(conn, int64(s.MaxRequestBytes)+1))

	line, err := reader.ReadBytes('\n')
	if len(line) > s.MaxRequestBytes {
		return Request{}, Errorf(CodeInvalidRequest, "request is larger than %d bytes", s.MaxRequestBytes)
	}

	if err != nil {
		return Request{}, Errorf(CodeInvalidRequest, "could not read a complete request line")
	}

	var request Request
	if err := json.Unmarshal(line, &request); err != nil {
		return Request{}, Errorf(CodeParseError, "request is not valid JSON")
	}

	if !validID(request.ID) {
		return Request{}, Errorf(CodeInvalidRequest, "request id must be a string or a number")
	}

	if request.JSONRPC != "2.0" || request.Method == "" {
		return request, Errorf(CodeInvalidRequest, `request must have jsonrpc "2.0" and a method`)
	}

	return request, nil
}

func validID(id json.RawMessage) bool {
	trimmed := bytes.TrimSpace(id)
	if len(trimmed) == 0 {
		return false
	}

	switch first := trimmed[0]; {
	case first == '"':
		var value string

		return json.Unmarshal(trimmed, &value) == nil
	case first == '-' || (first >= '0' && first <= '9'):
		var value json.Number

		return json.Unmarshal(trimmed, &value) == nil
	default:
		return false
	}
}

func (s *Server) call(ctx context.Context, uid uint32, request Request) (response Response) {
	response = Response{ID: request.ID}
	logger := s.Logger.With("method", request.Method, "id", string(request.ID), "uid", uid)

	method, ok := s.Registry.lookup(request.Method)
	if !ok {
		response.Error = Errorf(CodeMethodNotFound, "method %q does not exist", request.Method)

		return response
	}

	callCtx, cancel := context.WithTimeout(context.WithoutCancel(ctx), s.CallTimeout)
	defer cancel()

	started := time.Now()

	defer func() {
		if recovered := recover(); recovered != nil {
			logger.Error("method panicked", "panic", fmt.Sprint(recovered), "stack", string(debug.Stack()))
			response.Result = nil
			response.Error = Errorf(CodeInternalError, "internal agent error")
		}
	}()

	if method.LockKey != nil {
		key, err := method.LockKey(request.Params)
		if err != nil {
			response.Error = asRPCError(err, CodeInvalidParams, logger)

			return response
		}

		unlock, err := s.locks.lock(callCtx, key)
		if err != nil {
			response.Error = Errorf(CodeBusy, "another task for %s is still running", key)

			return response
		}

		defer unlock()
	}

	result, err := method.Handler(callCtx, request.Params)
	if err != nil {
		response.Error = asRPCError(err, CodeInternalError, logger)
		logger.Info("method failed", "code", response.Error.Code, "duration", time.Since(started))

		return response
	}

	if result == nil {
		result = struct{}{}
	}

	response.Result = result
	logger.Info("method succeeded", "duration", time.Since(started))

	return response
}

// asRPCError passes *Error values through. Anything else is logged in full
// and replaced by a generic message, so internal details never leak.
func asRPCError(err error, fallbackCode int, logger *slog.Logger) *Error {
	var rpcErr *Error
	if errors.As(err, &rpcErr) {
		return rpcErr
	}

	logger.Error("method error", "error", err)

	if fallbackCode == CodeInternalError {
		return Errorf(CodeInternalError, "internal agent error")
	}

	return Errorf(fallbackCode, "%s", err.Error())
}

func (s *Server) write(conn net.Conn, response Response) {
	response.JSONRPC = "2.0"

	data, err := json.Marshal(response)
	if err != nil {
		s.Logger.Error("could not encode response", "error", err)
		data, _ = json.Marshal(Response{
			JSONRPC: "2.0",
			ID:      response.ID,
			Error:   Errorf(CodeInternalError, "internal agent error"),
		})
	}

	_ = conn.SetWriteDeadline(time.Now().Add(10 * time.Second))
	_, _ = conn.Write(append(data, '\n'))
}
