package webserver

import (
	"fmt"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"time"
)

// The php.ini settings a website may change from the panel. Each website
// has a PHP-FPM pool of its own (one section of the account's pool), so its
// settings never reach another website. They are php_value/php_flag, so the
// site itself can still change them (ini_set, .user.ini).

// phpSettingRule checks the value of one setting.
type phpSettingRule struct {
	// flag settings are php_flag (on/off), the others php_value.
	flag bool
	// pattern must match the whole value.
	pattern *regexp.Regexp
	// min and max bound the number in the value, when max is above 0.
	min, max int
}

var (
	megabytesPattern = regexp.MustCompile(`^([1-9][0-9]{0,3})M$`)
	secondsPattern   = regexp.MustCompile(`^([1-9][0-9]{0,2})$`)
	countPattern     = regexp.MustCompile(`^([1-9][0-9]{3,5})$`)
	flagPattern      = regexp.MustCompile(`^(on|off)$`)
	timezonePattern  = regexp.MustCompile(`^[A-Za-z]+(/[A-Za-z0-9_+-]+){0,2}$`)
)

// phpSettingRules are the settings a website may change and their limits.
// The time limits stay within the 300 s Nginx, Apache and PHP-FPM give a
// request; uploads within the 1 GiB Apache accepts.
var phpSettingRules = map[string]phpSettingRule{
	"memory_limit":        {pattern: megabytesPattern, min: 32, max: 4096},
	"upload_max_filesize": {pattern: megabytesPattern, min: 1, max: 1024},
	"post_max_size":       {pattern: megabytesPattern, min: 1, max: 1024},
	"max_execution_time":  {pattern: secondsPattern, min: 10, max: 300},
	"max_input_time":      {pattern: secondsPattern, min: 10, max: 300},
	"max_input_vars":      {pattern: countPattern, min: 1000, max: 100000},
	"display_errors":      {flag: true, pattern: flagPattern},
	"date.timezone":       {pattern: timezonePattern},
}

// defaultPHPSettings apply to every website unless it changed them. They
// match the panel's defaults. date.timezone has none: php.ini's applies.
var defaultPHPSettings = map[string]string{
	"memory_limit":        "256M",
	"upload_max_filesize": "64M",
	"post_max_size":       "64M",
	"max_execution_time":  "120",
	"max_input_time":      "120",
	"max_input_vars":      "3000",
	"display_errors":      "off",
}

// phpSetting is one setting as the pool template sees it.
type phpSetting struct {
	// Directive is php_value or php_flag.
	Directive string
	Key       string
	Value     string
}

// validatePHPSettings returns an error unless every setting is one a
// website may change, with a valid value, and the POST limit holds the
// largest upload.
func validatePHPSettings(settings map[string]string) error {
	for key, value := range settings {
		rule, ok := phpSettingRules[key]
		if !ok {
			return fmt.Errorf("php setting %q cannot be changed", key)
		}

		match := rule.pattern.FindStringSubmatch(value)
		if match == nil {
			return fmt.Errorf("php setting %s has an invalid value %q", key, value)
		}

		if rule.max > 0 {
			number, _ := strconv.Atoi(match[1])
			if number < rule.min || number > rule.max {
				return fmt.Errorf("php setting %s must be between %d and %d", key, rule.min, rule.max)
			}
		}

		if key == "date.timezone" {
			if _, err := time.LoadLocation(value); err != nil || value == "Local" {
				return fmt.Errorf("php setting date.timezone has an unknown time zone %q", value)
			}
		}
	}

	effective := effectivePHPSettings(settings)
	if megabytes(effective["post_max_size"]) < megabytes(effective["upload_max_filesize"]) {
		return fmt.Errorf("post_max_size must be at least upload_max_filesize")
	}

	return nil
}

// effectivePHPSettings returns the defaults with settings applied.
func effectivePHPSettings(settings map[string]string) map[string]string {
	effective := make(map[string]string, len(defaultPHPSettings)+len(settings))

	for key, value := range defaultPHPSettings {
		effective[key] = value
	}

	for key, value := range settings {
		effective[key] = value
	}

	return effective
}

// phpSettingsData returns the settings of a website for the pool template,
// in a stable order.
func phpSettingsData(settings map[string]string) []phpSetting {
	effective := effectivePHPSettings(settings)

	keys := make([]string, 0, len(effective))
	for key := range effective {
		keys = append(keys, key)
	}

	slices.Sort(keys)

	data := make([]phpSetting, 0, len(keys))

	for _, key := range keys {
		directive := "php_value"
		if phpSettingRules[key].flag {
			directive = "php_flag"
		}

		data = append(data, phpSetting{Directive: directive, Key: key, Value: effective[key]})
	}

	return data
}

// clientMaxBodySize returns the largest request body Nginx lets through
// for a website: 256 MB, or its POST limit when that is larger.
func clientMaxBodySize(settings map[string]string) string {
	size := max(256, megabytes(effectivePHPSettings(settings)["post_max_size"]))

	return strconv.Itoa(size) + "m"
}

// megabytes returns the number of a value like "64M", or 0.
func megabytes(value string) int {
	number, _ := strconv.Atoi(strings.TrimSuffix(value, "M"))

	return number
}
