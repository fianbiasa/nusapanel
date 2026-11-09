<?php

namespace App\Support;

/**
 * The php.ini settings a website may change from the panel, their
 * defaults and the values offered. The agent applies them to the website's
 * own PHP-FPM pool and checks them again; its defaults are the same.
 *
 * Settings are kept in their php.ini form, the way the agent takes them:
 * sizes like "512M", numbers as strings, display_errors as "on" or "off",
 * and date.timezone only when it was chosen.
 */
final class PhpSettings
{
    /**
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'memory_limit' => '256M',
        'upload_max_filesize' => '64M',
        'post_max_size' => '64M',
        'max_execution_time' => '120',
        'max_input_time' => '120',
        'max_input_vars' => '3000',
        'display_errors' => 'off',
    ];

    /**
     * The memory limits offered.
     *
     * @var list<string>
     */
    public const MEMORY_LIMITS = ['64M', '128M', '256M', '512M', '768M', '1024M', '1536M', '2048M'];

    /**
     * The upload and POST sizes offered; Apache accepts at most 1 GiB.
     *
     * @var list<string>
     */
    public const SIZES = ['2M', '8M', '16M', '32M', '64M', '128M', '256M', '512M', '1024M'];

    /**
     * The settings of the form fields, in their php.ini form, without
     * those that equal the defaults.
     *
     * @param  array{memory_limit: string, upload_max_filesize: string, post_max_size: string, max_execution_time: int, max_input_time: int, max_input_vars: int, display_errors: bool, date_timezone: string|null}  $fields
     * @return array<string, string>
     */
    public static function fromFields(array $fields): array
    {
        $settings = [
            'memory_limit' => $fields['memory_limit'],
            'upload_max_filesize' => $fields['upload_max_filesize'],
            'post_max_size' => $fields['post_max_size'],
            'max_execution_time' => (string) $fields['max_execution_time'],
            'max_input_time' => (string) $fields['max_input_time'],
            'max_input_vars' => (string) $fields['max_input_vars'],
            'display_errors' => $fields['display_errors'] ? 'on' : 'off',
        ];

        $settings = array_diff_assoc($settings, self::DEFAULTS);

        if ($fields['date_timezone'] !== null && $fields['date_timezone'] !== '') {
            $settings['date.timezone'] = $fields['date_timezone'];
        }

        ksort($settings);

        return $settings;
    }

    /**
     * The settings a website runs with: the defaults with its own applied.
     *
     * @param  array<string, string>|null  $settings
     * @return array<string, string>
     */
    public static function effective(?array $settings): array
    {
        return [...self::DEFAULTS, ...($settings ?? [])];
    }

    /**
     * Megabytes of a size like "64M".
     */
    public static function megabytes(string $size): int
    {
        return (int) rtrim($size, 'M');
    }
}
