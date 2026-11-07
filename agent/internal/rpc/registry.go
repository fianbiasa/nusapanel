package rpc

import (
	"context"
	"encoding/json"
	"sort"
)

// HandlerFunc runs one agent method. Return an *Error for failures the caller
// should see; any other error is logged and reported as an internal error.
type HandlerFunc func(ctx context.Context, params json.RawMessage) (any, error)

// Method describes one registered agent method.
type Method struct {
	Handler HandlerFunc

	// LockKey, when set, returns the account the call changes. Calls that
	// return the same key run one at a time.
	LockKey func(params json.RawMessage) (string, error)
}

// Registry is the closed set of methods the agent will run. There is no
// "run this command" method, and there never should be.
type Registry struct {
	methods map[string]Method
}

// NewRegistry returns an empty registry.
func NewRegistry() *Registry {
	return &Registry{methods: map[string]Method{}}
}

// Register adds a method. It panics on a nil handler or a duplicate name,
// because both are programming errors.
func (r *Registry) Register(name string, method Method) {
	if method.Handler == nil {
		panic("rpc: nil handler for " + name)
	}

	if _, exists := r.methods[name]; exists {
		panic("rpc: duplicate method " + name)
	}

	r.methods[name] = method
}

// Names returns the registered method names in alphabetical order.
func (r *Registry) Names() []string {
	names := make([]string, 0, len(r.methods))
	for name := range r.methods {
		names = append(names, name)
	}

	sort.Strings(names)

	return names
}

func (r *Registry) lookup(name string) (Method, bool) {
	method, ok := r.methods[name]

	return method, ok
}
