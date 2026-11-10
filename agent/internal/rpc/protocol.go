// Package rpc implements the JSON-RPC 2.0 protocol rayxod speaks on its Unix
// socket: one request and one response per connection, each a single line of
// JSON terminated by "\n".
package rpc

import (
	"bytes"
	"encoding/json"
	"fmt"
)

// Standard JSON-RPC 2.0 error codes, plus the agent's own codes in the
// implementation-defined range (-32000 to -32099).
const (
	CodeParseError     = -32700
	CodeInvalidRequest = -32600
	CodeMethodNotFound = -32601
	CodeInvalidParams  = -32602
	CodeInternalError  = -32603

	// CodeActionFailed means the method ran but could not complete. The
	// message explains why and is safe to show to panel users.
	CodeActionFailed = -32000
	// CodeForbidden means the calling process may not use the agent.
	CodeForbidden = -32003
	// CodeBusy means another call holding the same account lock did not
	// finish before this call's deadline.
	CodeBusy = -32004
)

// Request is a JSON-RPC 2.0 request. Notifications (requests without an id)
// are not supported: every call gets a response.
type Request struct {
	JSONRPC string          `json:"jsonrpc"`
	ID      json.RawMessage `json:"id"`
	Method  string          `json:"method"`
	Params  json.RawMessage `json:"params,omitempty"`
}

// Response is a JSON-RPC 2.0 response. Exactly one of Result and Error is set.
type Response struct {
	JSONRPC string          `json:"jsonrpc"`
	ID      json.RawMessage `json:"id"`
	Result  any             `json:"result,omitempty"`
	Error   *Error          `json:"error,omitempty"`
}

// Error is a JSON-RPC 2.0 error object. Handlers return *Error for failures
// whose message the caller should see.
type Error struct {
	Code    int    `json:"code"`
	Message string `json:"message"`
}

func (e *Error) Error() string {
	return fmt.Sprintf("agent error %d: %s", e.Code, e.Message)
}

// Errorf returns an *Error with the given code and formatted message.
func Errorf(code int, format string, args ...any) *Error {
	return &Error{Code: code, Message: fmt.Sprintf(format, args...)}
}

// DecodeParams strictly decodes params into dst. Unknown fields are rejected
// so a typo in the panel never passes silently.
func DecodeParams(raw json.RawMessage, dst any) error {
	if len(bytes.TrimSpace(raw)) == 0 || bytes.Equal(bytes.TrimSpace(raw), []byte("null")) {
		raw = json.RawMessage("{}")
	}

	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.DisallowUnknownFields()

	if err := decoder.Decode(dst); err != nil {
		return Errorf(CodeInvalidParams, "invalid params: %v", err)
	}

	if decoder.More() {
		return Errorf(CodeInvalidParams, "invalid params: unexpected data after the params object")
	}

	return nil
}
