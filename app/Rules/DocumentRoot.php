<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The folder a subdomain is served from ends up in web server configs, so
 * this mirrors the agent's own check: a folder relative to the home, inside
 * domains/ with at least one folder below it (tmp/ holds PHP sessions and
 * .trash/ deleted files, neither may be served), of folder names that do
 * not start with a dot. Values are expected in the form
 * {@see self::normalize()} produces.
 */
class DocumentRoot implements ValidationRule
{
    public const MAX_LENGTH = 200;

    public const MAX_DEPTH = 16;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail('The :attribute must be a folder inside domains/, such as domains/example.com/public_html/blog.')->translate();
        }
    }

    /**
     * Bring user input into the stored form: trimmed, without slashes
     * around it.
     */
    public static function normalize(mixed $value): mixed
    {
        return is_string($value) ? trim(trim($value), '/') : $value;
    }

    public static function isValid(string $value): bool
    {
        $names = explode('/', $value);

        if (strlen($value) > self::MAX_LENGTH || count($names) > self::MAX_DEPTH || count($names) < 2 || $names[0] !== 'domains') {
            return false;
        }

        foreach ($names as $name) {
            if (preg_match('/^[A-Za-z0-9_-][A-Za-z0-9._-]{0,99}$/D', $name) !== 1) {
                return false;
            }
        }

        return true;
    }
}
