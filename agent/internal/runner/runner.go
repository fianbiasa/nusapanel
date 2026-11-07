// Package runner runs system programs for the agent. Programs are started
// directly with their arguments as separate strings, never through a shell,
// so no argument is ever interpreted as shell syntax.
package runner

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"os/exec"
	"path/filepath"
	"strings"
	"time"
)

// maxErrorOutput limits how much program output an *Error carries, so one
// noisy program cannot flood the agent log.
const maxErrorOutput = 4096

// environment is the complete environment every program gets. Nothing is
// inherited from the agent, so programs behave the same however nusad was
// started and never see the agent's own variables.
var environment = []string{
	"PATH=/usr/sbin:/usr/bin:/sbin:/bin",
	"LC_ALL=C",
}

// Error reports a program that could not be started, exited with a failure
// status, or was stopped because its context ended. It is meant for logs:
// the output may contain system details that panel users should not see.
type Error struct {
	// Program is the path of the program that was run.
	Program string
	// ExitCode is the program's exit status, or -1 when it did not exit on
	// its own (it could not be started or was killed).
	ExitCode int
	// Output is the program's combined stdout and stderr, trimmed and
	// shortened to a few kilobytes.
	Output string
	// Err is the underlying error, wrapping the context error when the
	// deadline passed or the call was cancelled.
	Err error
}

func (e *Error) Error() string {
	message := fmt.Sprintf("%s failed: %v", e.Program, e.Err)
	if e.ExitCode >= 0 {
		message = fmt.Sprintf("%s exited with code %d", e.Program, e.ExitCode)
	}

	if e.Output != "" {
		message += ": " + e.Output
	}

	return message
}

func (e *Error) Unwrap() error {
	return e.Err
}

// Run starts program with args, waits for it and returns its combined stdout
// and stderr. program must be an absolute path, so the agent never depends
// on $PATH. The program runs in / with a minimal fixed environment and no
// stdin, and is killed when ctx ends. Any failure is returned as *Error,
// together with whatever output the program produced.
func Run(ctx context.Context, program string, args ...string) ([]byte, error) {
	if !filepath.IsAbs(program) {
		return nil, &Error{Program: program, ExitCode: -1, Err: errors.New("program must be an absolute path")}
	}

	var output bytes.Buffer

	cmd := exec.CommandContext(ctx, program, args...)
	cmd.Dir = "/"
	cmd.Env = environment
	cmd.Stdout = &output
	cmd.Stderr = &output
	// A killed program's children may keep the output pipe open; stop
	// waiting for them shortly after the program itself is gone.
	cmd.WaitDelay = 5 * time.Second

	runErr := cmd.Run()
	if runErr == nil {
		return output.Bytes(), nil
	}

	failure := &Error{Program: program, ExitCode: -1, Output: shorten(output.String()), Err: runErr}

	if ctxErr := ctx.Err(); ctxErr != nil {
		failure.Err = fmt.Errorf("%w: %w", ctxErr, runErr)
	} else if exitErr, ok := errors.AsType[*exec.ExitError](runErr); ok {
		failure.ExitCode = exitErr.ExitCode()
	}

	return output.Bytes(), failure
}

// ExitCode returns the exit status carried by err, or -1 when err is not an
// *Error from a program that exited on its own.
func ExitCode(err error) int {
	if runErr, ok := errors.AsType[*Error](err); ok {
		return runErr.ExitCode
	}

	return -1
}

// shorten trims output and cuts it to maxErrorOutput bytes.
func shorten(output string) string {
	output = strings.TrimSpace(output)
	if len(output) <= maxErrorOutput {
		return output
	}

	return strings.ToValidUTF8(output[:maxErrorOutput], "") + " [truncated]"
}
