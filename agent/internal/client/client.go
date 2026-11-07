// Package client calls nusad over its Unix socket. It is used by nusactl and
// by tests; the panel has its own PHP client speaking the same protocol.
package client

import (
	"bufio"
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"strconv"

	"nusapanel/agent/internal/rpc"
)

// Call sends one request and returns the raw JSON result. Failures reported
// by the agent are returned as *rpc.Error.
func Call(ctx context.Context, socketPath, method string, params any) (json.RawMessage, error) {
	var dialer net.Dialer

	conn, err := dialer.DialContext(ctx, "unix", socketPath)
	if err != nil {
		return nil, fmt.Errorf("connect to agent at %s: %w", socketPath, err)
	}
	defer conn.Close()

	if deadline, ok := ctx.Deadline(); ok {
		_ = conn.SetDeadline(deadline)
	}

	request, err := newRequest(method, params)
	if err != nil {
		return nil, err
	}

	encoded, err := json.Marshal(request)
	if err != nil {
		return nil, fmt.Errorf("encode request: %w", err)
	}

	if _, err := conn.Write(append(encoded, '\n')); err != nil {
		return nil, fmt.Errorf("send request: %w", err)
	}

	line, err := bufio.NewReader(conn).ReadBytes('\n')
	if err != nil {
		return nil, fmt.Errorf("read response: %w", err)
	}

	var response struct {
		ID     json.RawMessage `json:"id"`
		Result json.RawMessage `json:"result"`
		Error  *rpc.Error      `json:"error"`
	}

	if err := json.Unmarshal(line, &response); err != nil {
		return nil, fmt.Errorf("decode response: %w", err)
	}

	sameRequest := bytes.Equal(response.ID, request.ID)

	// The agent answers with a null id when it rejects a call before reading
	// it (e.g. a forbidden caller), so pass that error through as well.
	if response.Error != nil && (sameRequest || isNull(response.ID)) {
		return nil, response.Error
	}

	if !sameRequest {
		return nil, errors.New("agent response does not match the request")
	}

	return response.Result, nil
}

func newRequest(method string, params any) (rpc.Request, error) {
	idBytes := make([]byte, 16)
	if _, err := rand.Read(idBytes); err != nil {
		return rpc.Request{}, fmt.Errorf("generate request id: %w", err)
	}

	if params == nil {
		params = struct{}{}
	}

	encodedParams, err := json.Marshal(params)
	if err != nil {
		return rpc.Request{}, fmt.Errorf("encode params: %w", err)
	}

	return rpc.Request{
		JSONRPC: "2.0",
		ID:      json.RawMessage(strconv.Quote(hex.EncodeToString(idBytes))),
		Method:  method,
		Params:  encodedParams,
	}, nil
}

func isNull(id json.RawMessage) bool {
	trimmed := bytes.TrimSpace(id)

	return len(trimmed) == 0 || bytes.Equal(trimmed, []byte("null"))
}
