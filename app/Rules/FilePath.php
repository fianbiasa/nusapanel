<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A path in the file manager, relative to the account's home ("" is the
 * home itself), or with $nameOnly the name of one file or folder. It
 * mirrors the agent's own check: single slashes between names, no "." or
 * "..", no control characters, and Linux's length limits.
 */
class FilePath implements ValidationRule
{
    public const MAX_PATH_BYTES = 4096;

    public const MAX_NAME_BYTES = 255;

    public function __construct(private bool $nameOnly = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value ??= '';

        if (! is_string($value) || ! ($this->nameOnly ? self::isValidName($value) : self::isValidPath($value))) {
            $fail($this->nameOnly
                ? 'The :attribute may not be empty, "." or "..", and may not contain a slash or control characters.'
                : 'The :attribute is not a valid path in the home folder.')->translate();
        }
    }

    public static function isValidPath(string $path): bool
    {
        if ($path === '') {
            return true;
        }

        if (strlen($path) > self::MAX_PATH_BYTES) {
            return false;
        }

        foreach (explode('/', $path) as $name) {
            if (! self::isValidName($name)) {
                return false;
            }
        }

        return true;
    }

    public static function isValidName(string $name): bool
    {
        return $name !== ''
            && $name !== '.'
            && $name !== '..'
            && strlen($name) <= self::MAX_NAME_BYTES
            && mb_check_encoding($name, 'UTF-8')
            && preg_match('/[\x00-\x1f\x7f\/]/', $name) !== 1;
    }
}
