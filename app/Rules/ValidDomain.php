<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Domain names end up in web server configs and paths on the server, so
 * this mirrors the agent's own check. Values are expected in the form
 * {@see self::normalize()} produces: lowercase ASCII (punycode).
 */
class ValidDomain implements ValidationRule
{
    public const LABEL_PATTERN = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/D';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isWellFormed($value)) {
            $fail('The :attribute must be a valid domain name, such as example.com.')->translate();

            return;
        }

        if (str_starts_with($value, 'www.')) {
            $fail('Add the :attribute without "www." — www is served automatically.')->translate();

            return;
        }

        $hostname = self::serverHostname();

        if ($hostname !== null && ($value === $hostname || str_ends_with($value, '.'.$hostname))) {
            $fail('The :attribute belongs to this server\'s own hostname and cannot be added.')->translate();
        }
    }

    /**
     * Bring user input into the stored form: trimmed, without one trailing
     * dot, lowercase, and in punycode when it contains non-ASCII letters.
     * Values that cannot be converted are left for validation to reject.
     */
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        if (str_ends_with($value, '.')) {
            $value = substr($value, 0, -1);
        }

        $value = mb_strtolower($value);

        if (preg_match('/[^\x00-\x7F]/', $value) === 1) {
            $ascii = idn_to_ascii($value, IDNA_NONTRANSITIONAL_TO_ASCII);

            if ($ascii !== false) {
                $value = $ascii;
            }
        }

        return $value === '' ? null : $value;
    }

    /**
     * Whether the value is a domain name in the stored form, without the
     * checks against "www." and the panel's own hostname.
     */
    public static function isWellFormed(string $value): bool
    {
        if (strlen($value) > 253) {
            return false;
        }

        $labels = explode('.', $value);

        if (count($labels) < 2 || ctype_digit(end($labels))) {
            return false;
        }

        foreach ($labels as $label) {
            if (preg_match(self::LABEL_PATTERN, $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * The panel's own hostname, which serves the panel and must never be
     * handed to a hosting account.
     */
    private static function serverHostname(): ?string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? rtrim(strtolower($host), '.') : null;
    }
}
