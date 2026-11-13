<?php

namespace App\Support;

/**
 * Passwords the panel makes up for someone to read off a screen and type:
 * twelve characters of upper and lower case letters with three digits,
 * without symbols and without characters that look alike (0 O, 1 l I).
 * The same rule as the generate button in the panel's password fields.
 */
final class GeneratedPassword
{
    public const LENGTH = 12;

    private const UPPERCASE = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const LOWERCASE = 'abcdefghijkmnopqrstuvwxyz';

    private const DIGITS = '23456789';

    private const DIGIT_COUNT = 3;

    /**
     * A new random password, with at least one upper and one lower case
     * letter.
     */
    public static function make(): string
    {
        $characters = [self::pick(self::UPPERCASE), self::pick(self::LOWERCASE)];

        for ($digit = 0; $digit < self::DIGIT_COUNT; $digit++) {
            $characters[] = self::pick(self::DIGITS);
        }

        while (count($characters) < self::LENGTH) {
            $characters[] = self::pick(self::UPPERCASE.self::LOWERCASE);
        }

        // Fisher-Yates with a secure random source; shuffle() is not one.
        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }

    private static function pick(string $alphabet): string
    {
        return $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
}
