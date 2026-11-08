<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The path of a URL redirect ends up in a web server config, so this
 * mirrors the agent's own check: "/" or segments of letters, digits and
 * ._~- between single slashes. Values are expected in the form
 * {@see self::normalize()} produces.
 */
class ValidRedirectPath implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail('The :attribute may only contain letters, digits, dots, hyphens, underscores and tildes between slashes, such as /promo or /blog/2026.')->translate();
        }
    }

    /**
     * Bring user input into the stored form: trimmed, starting with one
     * slash and without a trailing one (except for "/" itself).
     */
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $value = '/'.ltrim($value, '/');

        return $value === '/' ? $value : rtrim($value, '/');
    }

    private static function isValid(string $value): bool
    {
        if ($value === '/') {
            return true;
        }

        if (strlen($value) > 255 || preg_match('#^(/[A-Za-z0-9._~-]+)+$#', $value) !== 1) {
            return false;
        }

        $segments = explode('/', substr($value, 1));

        return ! in_array('.', $segments, true) && ! in_array('..', $segments, true);
    }
}
