<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An IPv4 or IPv6 address websites can be served on: not loopback,
 * link-local, multicast or unspecified, and no IPv4 address written as
 * IPv6. The agent checks the same.
 */
class ServableIpAddress implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $packed = is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false ? inet_pton($value) : false;

        if ($packed === false || ! self::isServable($packed)) {
            $fail('The :attribute must be an IP address websites can be served on.')->translate();
        }
    }

    /**
     * @param  string  $packed  The address as inet_pton() returns it.
     */
    private static function isServable(string $packed): bool
    {
        if (strlen($packed) === 4) {
            $first = ord($packed[0]);

            return $first !== 0 && $first !== 127 && $first < 224 && ! ($first === 169 && ord($packed[1]) === 254);
        }

        $first = ord($packed[0]);

        return $packed !== str_repeat("\0", 16)
            && $packed !== str_repeat("\0", 15)."\1"
            && $first !== 0xFF
            && ! ($first === 0xFE && (ord($packed[1]) & 0xC0) === 0x80)
            && ! str_starts_with($packed, str_repeat("\0", 10)."\xFF\xFF");
    }
}
