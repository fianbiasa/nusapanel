package runner_test

import (
	"context"
	"errors"
	"strings"
	"testing"
	"time"

	"rayxo/agent/internal/runner"
)

func TestRunReturnsTheOutput(t *testing.T) {
	output, err := runner.Run(context.Background(), "/bin/echo", "halo")
	if err != nil {
		t.Fatalf("run failed: %v", err)
	}

	if string(output) != "halo\n" {
		t.Fatalf("unexpected output %q", output)
	}
}

func TestArgumentsAreNotInterpretedByAShell(t *testing.T) {
	args := []string{"$(id)", "`id`", "; rm -rf /", "*", "a b", "$HOME", "|", "&&"}

	output, err := runner.Run(context.Background(), "/bin/echo", args...)
	if err != nil {
		t.Fatalf("run failed: %v", err)
	}

	want := strings.Join(args, " ") + "\n"
	if string(output) != want {
		t.Fatalf("arguments were changed: got %q, want %q", output, want)
	}
}

func TestFailuresCarryTheExitCodeAndOutput(t *testing.T) {
	output, err := runner.Run(context.Background(), "/bin/sh", "-c", "echo to stdout; echo to stderr >&2; exit 7")

	runErr := assertRunError(t, err)

	if runErr.Program != "/bin/sh" || runErr.ExitCode != 7 {
		t.Fatalf("unexpected program %q or exit code %d", runErr.Program, runErr.ExitCode)
	}

	if runErr.Output != "to stdout\nto stderr" {
		t.Fatalf("unexpected error output %q", runErr.Output)
	}

	if string(output) != "to stdout\nto stderr\n" {
		t.Fatalf("unexpected output %q", output)
	}

	if runner.ExitCode(err) != 7 {
		t.Fatalf("ExitCode returned %d", runner.ExitCode(err))
	}
}

func TestProgramsThatCannotStartAreReported(t *testing.T) {
	_, err := runner.Run(context.Background(), "/nonexistent/rayxo-program")

	runErr := assertRunError(t, err)
	if runErr.ExitCode != -1 {
		t.Fatalf("expected exit code -1, got %d", runErr.ExitCode)
	}
}

func TestRelativeProgramsAreRefused(t *testing.T) {
	_, err := runner.Run(context.Background(), "echo", "halo")

	assertRunError(t, err)
}

func TestProgramsAreKilledWhenTheDeadlinePasses(t *testing.T) {
	ctx, cancel := context.WithTimeout(context.Background(), 100*time.Millisecond)
	defer cancel()

	started := time.Now()
	_, err := runner.Run(ctx, "/bin/sleep", "10")

	if elapsed := time.Since(started); elapsed > 5*time.Second {
		t.Fatalf("run took %v after the deadline", elapsed)
	}

	runErr := assertRunError(t, err)
	if runErr.ExitCode != -1 {
		t.Fatalf("expected exit code -1 for a killed program, got %d", runErr.ExitCode)
	}

	if !errors.Is(err, context.DeadlineExceeded) {
		t.Fatalf("expected a deadline error, got %v", err)
	}
}

func TestProgramsGetAFixedEnvironment(t *testing.T) {
	t.Setenv("RAYXO_SECRET", "do-not-pass-on")

	output, err := runner.Run(context.Background(), "/usr/bin/env")
	if err != nil {
		t.Fatalf("run failed: %v", err)
	}

	if strings.Contains(string(output), "RAYXO_SECRET") {
		t.Fatalf("the agent environment leaked into the program: %q", output)
	}

	if !strings.Contains(string(output), "LC_ALL=C") {
		t.Fatalf("expected LC_ALL=C in %q", output)
	}
}

func TestLongOutputIsShortenedInErrors(t *testing.T) {
	_, err := runner.Run(context.Background(), "/bin/sh", "-c", "i=0; while [ $i -lt 2000 ]; do echo 0123456789; i=$((i+1)); done; exit 1")

	runErr := assertRunError(t, err)
	if len(runErr.Output) > 4200 || !strings.HasSuffix(runErr.Output, "[truncated]") {
		t.Fatalf("output was not shortened: %d bytes", len(runErr.Output))
	}
}

func assertRunError(t *testing.T, err error) *runner.Error {
	t.Helper()

	var runErr *runner.Error
	if !errors.As(err, &runErr) {
		t.Fatalf("expected *runner.Error, got %v", err)
	}

	return runErr
}

func TestRunInputFeedsStdinAndKeepsStderrForErrors(t *testing.T) {
	output, err := runner.RunInput(context.Background(), []byte("secret input\n"), "/bin/cat")
	if err != nil || string(output) != "secret input\n" {
		t.Fatalf("cat: %q, %v", output, err)
	}

	output, err = runner.RunInput(context.Background(), []byte("ignored"), "/bin/sh", "-c", "echo out; echo problem >&2; exit 3")
	if string(output) != "out\n" {
		t.Fatalf("stdout = %q; stderr must not mix in", output)
	}

	runErr, ok := errors.AsType[*runner.Error](err)
	if !ok || runErr.ExitCode != 3 || runErr.Output != "problem" {
		t.Fatalf("got %#v", err)
	}
}
