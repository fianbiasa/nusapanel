<?php

namespace App\Support;

use Closure;

/**
 * Validates the part of a database or database user name a customer
 * chooses: the name is <username>_<part>, which the agent checks too.
 */
final class DatabaseNames
{
    public const MAX_DATABASE = 64;

    public const MAX_USER = 32;

    /**
     * Lowercase, without spaces around it or the "<username>_" a customer
     * may have typed along.
     */
    public static function normalize(mixed $value, string $username): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = strtolower(trim($value));

        return str_starts_with($value, $username.'_') ? substr($value, strlen($username) + 1) : $value;
    }

    /**
     * The rules of a name part for the account of username, at most
     * $maximum characters for the whole name.
     *
     * @return list<string|Closure>
     */
    public static function rules(string $username, int $maximum): array
    {
        $length = $maximum - strlen($username) - 1;

        return [
            'string',
            'regex:/^[a-z0-9_]+$/D',
            function (string $attribute, mixed $value, Closure $fail) use ($length): void {
                if (is_string($value) && strlen($value) > $length) {
                    $fail(__('The name may have at most :count characters after the prefix.', ['count' => $length]));
                }
            },
        ];
    }
}
