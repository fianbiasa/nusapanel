// Package fpmpool reads the administrator's template of the process manager
// settings every website's PHP-FPM pool gets, /etc/rayxo/php-fpm-pool.conf:
// "key = value" lines, ";" or "#" comments. Only process manager settings
// are allowed; who a pool runs as, where it listens and what PHP may do
// stay rayxod's.
package fpmpool

import (
	"bufio"
	"errors"
	"fmt"
	"os"
	"regexp"
	"strconv"
	"strings"
)

// Setting is one pool directive.
type Setting struct {
	Key   string
	Value string
}

// Defaults are the settings of a pool without a template, in the order
// they are written.
var Defaults = []Setting{
	{"pm", "ondemand"},
	{"pm.max_children", "10"},
	{"pm.process_idle_timeout", "30s"},
	{"pm.max_requests", "500"},
	{"request_terminate_timeout", "300s"},
}

var (
	durationPattern = regexp.MustCompile(`^[0-9]{1,6}[smh]?$`)
	counts          = map[string]int{
		"pm.max_children":      1000,
		"pm.start_servers":     1000,
		"pm.min_spare_servers": 1000,
		"pm.max_spare_servers": 1000,
		"pm.max_requests":      1000000,
	}
	durations = map[string]bool{"pm.process_idle_timeout": true, "request_terminate_timeout": true}
)

// Parse reads a template and returns the defaults with its settings in
// their place, then those the defaults lack, in the template's order.
func Parse(content string) ([]Setting, error) {
	values := map[string]string{}
	var order []string

	scanner := bufio.NewScanner(strings.NewReader(content))
	for line := 1; scanner.Scan(); line++ {
		text := strings.TrimSpace(scanner.Text())
		if text == "" || strings.HasPrefix(text, ";") || strings.HasPrefix(text, "#") {
			continue
		}

		key, value, found := strings.Cut(text, "=")
		key, value = strings.TrimSpace(key), strings.TrimSpace(value)

		if !found || key == "" {
			return nil, fmt.Errorf("line %d: expected \"key = value\"", line)
		}

		if err := check(key, value); err != nil {
			return nil, fmt.Errorf("line %d: %w", line, err)
		}

		if _, seen := values[key]; seen {
			return nil, fmt.Errorf("line %d: %s is set twice", line, key)
		}

		values[key] = value
		order = append(order, key)
	}

	if err := scanner.Err(); err != nil {
		return nil, err
	}

	settings := make([]Setting, 0, len(Defaults)+len(order))

	for _, setting := range Defaults {
		if value, ok := values[setting.Key]; ok {
			setting.Value = value
			delete(values, setting.Key)
		}

		settings = append(settings, setting)
	}

	for _, key := range order {
		if value, ok := values[key]; ok {
			settings = append(settings, Setting{key, value})
		}
	}

	return settings, consistent(settings)
}

// Load reads the template at path; a missing file gives the defaults.
func Load(path string) ([]Setting, error) {
	content, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) || path == "" {
		return Defaults, nil
	}

	if err != nil {
		return nil, err
	}

	return Parse(string(content))
}

func check(key, value string) error {
	switch {
	case key == "pm":
		if value != "ondemand" && value != "dynamic" && value != "static" {
			return errors.New("pm must be ondemand, dynamic or static")
		}
	case counts[key] > 0:
		number, err := strconv.Atoi(value)
		if err != nil || number < 0 || number > counts[key] || (key == "pm.max_children" && number < 1) {
			return fmt.Errorf("%s must be a number up to %d", key, counts[key])
		}
	case durations[key]:
		if !durationPattern.MatchString(value) {
			return fmt.Errorf("%s must be a duration such as 30s, 5m or 1h", key)
		}
	default:
		return fmt.Errorf("%s cannot be set here; only process manager settings can", key)
	}

	return nil
}

// consistent checks what PHP-FPM needs of dynamic process management.
func consistent(settings []Setting) error {
	get := func(key string) int {
		for _, setting := range settings {
			if setting.Key == key {
				number, _ := strconv.Atoi(setting.Value)

				return number
			}
		}

		return -1
	}

	if settings[0].Value != "dynamic" {
		return nil
	}

	maxChildren, start, minSpare, maxSpare := get("pm.max_children"), get("pm.start_servers"), get("pm.min_spare_servers"), get("pm.max_spare_servers")
	if minSpare < 1 || maxSpare < 1 {
		return errors.New("pm = dynamic needs pm.min_spare_servers and pm.max_spare_servers")
	}

	if minSpare > maxSpare || maxSpare > maxChildren || (start >= 0 && (start < minSpare || start > maxSpare)) {
		return errors.New("pm = dynamic needs min_spare_servers <= start_servers <= max_spare_servers <= max_children")
	}

	return nil
}
