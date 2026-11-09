// Package cron renders the crontab of a hosting account from the jobs the
// panel keeps: a schedule and a shell command each, which the cron daemon
// runs as the account's user. Schedules and commands are validated, so a job
// can never add a line or a variable of its own.
package cron

import (
	"errors"
	"fmt"
	"slices"
	"strconv"
	"strings"
)

const (
	// MaxJobs is the most jobs one crontab may have.
	MaxJobs = 100
	// MaxCommand is the longest command, in bytes.
	MaxCommand = 1024
)

// Job is one cron job: when, and what to run.
type Job struct {
	Schedule string `json:"schedule"`
	Command  string `json:"command"`
}

// specials are the @ schedules cron knows, except @reboot: a job of a
// hosting account only runs on a schedule.
var specials = []string{"@hourly", "@daily", "@midnight", "@weekly", "@monthly", "@yearly", "@annually"}

// field is one of the five fields of a schedule.
type field struct {
	name     string
	min, max int
	// names stand for min, min+1, ...
	names []string
}

var fields = []field{
	{name: "minute", min: 0, max: 59},
	{name: "hour", min: 0, max: 23},
	{name: "day of month", min: 1, max: 31},
	{name: "month", min: 1, max: 12, names: []string{"jan", "feb", "mar", "apr", "may", "jun", "jul", "aug", "sep", "oct", "nov", "dec"}},
	{name: "day of week", min: 0, max: 7, names: []string{"sun", "mon", "tue", "wed", "thu", "fri", "sat"}},
}

// ValidateSchedule returns an error unless schedule is five fields separated
// by single spaces, or one of the @ schedules except @reboot. A field is a
// comma-separated list of "*", a value or a range (lowercase names allowed
// for months and weekdays), "*" and ranges optionally with "/step".
func ValidateSchedule(schedule string) error {
	if slices.Contains(specials, schedule) {
		return nil
	}

	parts := strings.Split(schedule, " ")
	if len(parts) != len(fields) {
		return fmt.Errorf("schedule %q must have five fields separated by single spaces, or be one of %s", schedule, strings.Join(specials, ", "))
	}

	for i, part := range parts {
		if err := fields[i].validate(part); err != nil {
			return fmt.Errorf("%s of schedule %q: %w", fields[i].name, schedule, err)
		}
	}

	return nil
}

func (f field) validate(value string) error {
	if value == "" {
		return errors.New("is empty")
	}

	for item := range strings.SplitSeq(value, ",") {
		base, step, hasStep := strings.Cut(item, "/")

		if hasStep {
			if n, err := number(step); err != nil || n < 1 || n > f.max {
				return fmt.Errorf("step %q must be 1 to %d", step, f.max)
			}
		}

		if base == "*" {
			continue
		}

		low, high, isRange := strings.Cut(base, "-")

		if hasStep && !isRange {
			return fmt.Errorf("%q: a step needs * or a range", item)
		}

		from, err := f.value(low)
		if err != nil {
			return err
		}

		if !isRange {
			continue
		}

		to, err := f.value(high)
		if err != nil {
			return err
		}

		if to < from {
			return fmt.Errorf("range %q runs backwards", base)
		}
	}

	return nil
}

// value parses a number or name of the field.
func (f field) value(s string) (int, error) {
	if i := slices.Index(f.names, s); i >= 0 {
		return f.min + i, nil
	}

	n, err := number(s)
	if err != nil || n < f.min || n > f.max {
		return 0, fmt.Errorf("%q must be %d to %d", s, f.min, f.max)
	}

	return n, nil
}

// number parses one or two digits.
func number(s string) (int, error) {
	if s == "" || len(s) > 2 || strings.Trim(s, "0123456789") != "" {
		return 0, fmt.Errorf("%q is not a number", s)
	}

	return strconv.Atoi(s)
}

// ValidateCommand returns an error unless command is one line of at most
// MaxCommand bytes.
func ValidateCommand(command string) error {
	if strings.TrimSpace(command) == "" {
		return errors.New("command is empty")
	}

	if len(command) > MaxCommand {
		return fmt.Errorf("command may have at most %d bytes", MaxCommand)
	}

	if strings.ContainsAny(command, "\n\r\x00") {
		return errors.New("command must be a single line")
	}

	return nil
}

// Validate checks every job and their number.
func Validate(jobs []Job) error {
	if len(jobs) > MaxJobs {
		return fmt.Errorf("at most %d jobs", MaxJobs)
	}

	for _, job := range jobs {
		if err := ValidateSchedule(job.Schedule); err != nil {
			return err
		}

		if err := ValidateCommand(job.Command); err != nil {
			return err
		}
	}

	return nil
}

// Render returns the crontab of jobs, which must be valid. Commands run in
// bash with a plain PATH, and their output is not mailed. A "%" that is not
// escaped yet is escaped, since cron would otherwise cut the command there:
// commands are written as they would be typed in a terminal.
func Render(jobs []Job) []byte {
	var crontab strings.Builder

	crontab.WriteString("# Written by nusapanel from the Cron Jobs page of the panel; changes made here are replaced.\n")
	crontab.WriteString("SHELL=/bin/bash\n")
	crontab.WriteString("PATH=/usr/local/bin:/usr/bin:/bin\n")
	crontab.WriteString("MAILTO=\"\"\n")

	for _, job := range jobs {
		crontab.WriteString("\n" + job.Schedule + " " + escapePercent(job.Command) + "\n")
	}

	return []byte(crontab.String())
}

// escapePercent puts a backslash before every "%" that has none.
func escapePercent(command string) string {
	var escaped strings.Builder

	for i := range len(command) {
		if command[i] == '%' && (i == 0 || command[i-1] != '\\') {
			escaped.WriteByte('\\')
		}

		escaped.WriteByte(command[i])
	}

	return escaped.String()
}
