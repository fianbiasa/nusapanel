<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Where a URL redirect goes ends up in a web server config, so this mirrors
 * the agent's own check: an absolute http:// or https:// URL to a valid
 * host, without user name or password, and without characters that mean
 * something to Nginx. Values are expected in the form
 * {@see self::normalize()} produces.
 */
class ValidRedirectDestination implements ValidationRule
{
    /**
     * @param  bool  $wildcard  Whether the rest of the path is appended, which needs a URL without query or fragment.
     */
    public function __construct(private bool $wildcard = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail('The :attribute must be a full address starting with http:// or https://, such as https://example.com/page.')->translate();

            return;
        }

        if ($this->wildcard && strpbrk($value, '?#') !== false) {
            $fail('The :attribute of a redirect that includes everything under its path cannot contain ? or #.')->translate();
        }
    }

    /**
     * Bring user input into the stored form: trimmed, https:// when no
     * scheme was typed, with the scheme and host lowercase and an
     * internationalized host in punycode. Values that do not look like a
     * URL are left for validation to reject.
     */
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        // "example.com/page" means https://example.com/page.
        if ($value !== '' && preg_match('#^[a-z][a-z0-9+.-]*:#i', $value) !== 1 && ! str_starts_with($value, '/')) {
            $value = 'https://'.$value;
        }

        if (preg_match('#^(https?)://([^/?\#]*)(.*)$#is', $value, $parts) !== 1) {
            return $value === '' ? null : $value;
        }

        [, $scheme, $authority, $rest] = $parts;

        preg_match('/^(.*?)(:\d+)?$/s', $authority, $hostAndPort);
        $host = mb_strtolower($hostAndPort[1] ?? $authority);
        $port = $hostAndPort[2] ?? '';

        if (preg_match('/[^\x00-\x7F]/', $host) === 1) {
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII);

            if ($ascii !== false) {
                $host = $ascii;
            }
        }

        return strtolower($scheme).'://'.$host.$port.$rest;
    }

    private static function isValid(string $value): bool
    {
        if (strlen($value) > 2048 || preg_match('/^[A-Za-z0-9._~:\/?#@!&()*+,=%\[\]-]+$/', $value) !== 1) {
            return false;
        }

        if (! str_starts_with($value, 'http://') && ! str_starts_with($value, 'https://')) {
            return false;
        }

        $parts = parse_url($value);

        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || ! isset($parts['host'])) {
            return false;
        }

        $host = trim($parts['host'], '[]');

        return ValidDomain::isWellFormed($host) || filter_var($host, FILTER_VALIDATE_IP) !== false;
    }
}
