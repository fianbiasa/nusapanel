//go:build !linux && !darwin

package sysinfo

import (
	"errors"
	"runtime"
)

func collect() (*Info, error) {
	return nil, errors.New("system.info is not supported on " + runtime.GOOS)
}
