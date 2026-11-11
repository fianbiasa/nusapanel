<?php

namespace App\Support;

/**
 * Email addresses a customer types as a forwarder's or catch-all's
 * destination. They go into Postfix's alias maps comma separated, so they
 * are kept to plain, lowercase addresses: no quotes, spaces, commas or
 * comments.
 */
final class MailAddress
{
    /**
     * The most destinations one forwarder may have.
     */
    public const MAX_DESTINATIONS = 20;

    private const PATTERN = '/^[a-z0-9._%+=-]{1,64}@(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/D';

    /**
     * Whether address (already normalized) is a plain address.
     */
    public static function isValid(string $address): bool
    {
        return strlen($address) <= 254
            && preg_match(self::PATTERN, $address) === 1
            && ! str_contains($address, '..')
            && ! str_starts_with($address, '.')
            && ! str_contains($address, '.@');
    }

    /**
     * The address as it is stored: trimmed and lowercase.
     */
    public static function normalize(string $address): string
    {
        return strtolower(trim($address));
    }

    /**
     * The addresses in text typed one per line or separated by commas,
     * semicolons or spaces: normalized, without empty ones or repeats.
     *
     * @return list<string>
     */
    public static function parseList(string $text): array
    {
        $addresses = preg_split('/[\s,;]+/', $text) ?: [];

        return array_values(array_unique(array_filter(
            array_map(self::normalize(...), $addresses),
            fn (string $address): bool => $address !== '',
        )));
    }

    /**
     * The domain of a normalized address.
     */
    public static function domain(string $address): string
    {
        return substr($address, (int) strrpos($address, '@') + 1);
    }
}
