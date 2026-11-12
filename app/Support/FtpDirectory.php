<?php

namespace App\Support;

use Closure;

/**
 * Validates the folder an FTP login is shut in: a path relative to the
 * account's home, where "" is the whole home. The agent checks it again.
 */
final class FtpDirectory
{
    public const MAX_LENGTH = 200;

    public const MAX_DEPTH = 16;

    /**
     * Without spaces or slashes around it, so "/" and "" both mean the home.
     */
    public static function normalize(mixed $value): mixed
    {
        if ($value === null) {
            return '';
        }

        return is_string($value) ? trim(trim($value), '/') : $value;
    }

    /**
     * @return list<string|Closure>
     */
    public static function rules(): array
    {
        return [
            'string',
            'max:'.self::MAX_LENGTH,
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || $value === '') {
                    return;
                }

                $names = explode('/', $value);

                if (count($names) > self::MAX_DEPTH) {
                    $fail(__('The folder may be at most :count folders deep.', ['count' => self::MAX_DEPTH]));

                    return;
                }

                foreach ($names as $name) {
                    if ($name === '.' || $name === '..' || preg_match('/^[A-Za-z0-9._-]+$/D', $name) !== 1) {
                        $fail(__('Use folder names of letters, digits, dots, dashes and underscores, separated by single slashes.'));

                        return;
                    }
                }
            },
        ];
    }
}
