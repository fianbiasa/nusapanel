<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A cron schedule as the agent accepts it: five fields separated by single
 * spaces, or one of the @ schedules except @reboot. A field is a
 * comma-separated list of "*", a value or a range (lowercase names for months
 * and weekdays), where "*" and ranges may have a "/step".
 */
class CronSchedule implements ValidationRule
{
    public const SPECIALS = ['@hourly', '@daily', '@midnight', '@weekly', '@monthly', '@yearly', '@annually'];

    /**
     * Minimum, maximum and names of each field.
     *
     * @var list<array{int, int, list<string>}>
     */
    private const FIELDS = [
        [0, 59, []],
        [0, 23, []],
        [1, 31, []],
        [1, 12, ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec']],
        [0, 7, ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat']],
    ];

    /**
     * Lowercase, with single spaces between the fields.
     */
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return strtolower((string) preg_replace('/\s+/', ' ', trim($value)));
    }

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail(__('Use five fields (minute hour day month weekday), such as */5 * * * *, or @hourly, @daily, @weekly, @monthly or @yearly.'));
        }
    }

    public static function isValid(string $schedule): bool
    {
        if (in_array($schedule, self::SPECIALS, true)) {
            return true;
        }

        $parts = explode(' ', $schedule);

        if (count($parts) !== count(self::FIELDS)) {
            return false;
        }

        foreach ($parts as $i => $part) {
            [$min, $max, $names] = self::FIELDS[$i];

            if (! self::fieldIsValid($part, $min, $max, $names)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $names
     */
    private static function fieldIsValid(string $value, int $min, int $max, array $names): bool
    {
        if ($value === '') {
            return false;
        }

        foreach (explode(',', $value) as $item) {
            $parts = explode('/', $item, 2);
            $base = $parts[0];
            $hasStep = count($parts) === 2;

            if ($hasStep) {
                $step = self::number($parts[1]);

                if ($step === null || $step < 1 || $step > $max) {
                    return false;
                }
            }

            if ($base === '*') {
                continue;
            }

            $range = explode('-', $base, 2);
            $isRange = count($range) === 2;

            if ($hasStep && ! $isRange) {
                return false;
            }

            $from = self::value($range[0], $min, $max, $names);

            if ($from === null) {
                return false;
            }

            if ($isRange) {
                $to = self::value($range[1], $min, $max, $names);

                if ($to === null || $to < $from) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $names
     */
    private static function value(string $value, int $min, int $max, array $names): ?int
    {
        $index = array_search($value, $names, true);

        if ($index !== false) {
            return $min + $index;
        }

        $number = self::number($value);

        return $number !== null && $number >= $min && $number <= $max ? $number : null;
    }

    private static function number(string $value): ?int
    {
        return preg_match('/^\d{1,2}$/', $value) === 1 ? (int) $value : null;
    }
}
