//go:build !linux

package sitelogs

import (
	"context"
	"errors"
	"io"
)

// DefaultConfig is a configuration without GoAccess: reports need Linux.
func DefaultConfig() Config {
	return Config{
		LogDir:   "/var/log/rayxo/domains",
		NginxDir: "/etc/nginx/rayxo",
		GoAccess: func(context.Context, io.Reader, string, io.Writer) error { return errors.ErrUnsupported },
	}
}
