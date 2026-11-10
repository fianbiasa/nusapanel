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
     * The defaults on a server whose websites upload uploadDefault when
     * they did not choose their own size. DEFAULTS stays what the agent
     * assumes for a setting it is not sent.
     *
     * @return array<string, string>
     */
    public static function defaults(string $uploadDefault): array
    {
        return [...self::DEFAULTS, 'upload_max_filesize' => $uploadDefault, 'post_max_size' => $uploadDefault];
    }

    /**
     * The upload and POST sizes offered on a server that allows at most limit.
     *
     * @return list<string>
     */
    public static function sizesUpTo(string $limit): array
    {
        return array_values(array_filter(self::SIZES, fn (string $size): bool => self::megabytes($size) <= self::megabytes($limit)));
    }

    /**
     * The settings of the form fields, in their php.ini form, without
     * those that equal the server's defaults ({@see defaults()}).
     *
     * @param  array{memory_limit: string, upload_max_filesize: string, post_max_size: string, max_execution_time: int, max_input_time: int, max_input_vars: int, display_errors: bool, date_timezone: string|null}  $fields
     * @param  array<string, string>  $defaults
     * @return array<string, string>
     */
    public static function fromFields(array $fields, array $defaults): array
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

        $settings = array_diff_assoc($settings, $defaults);

        if ($fields['date_timezone'] !== null && $fields['date_timezone'] !== '') {
            $settings['date.timezone'] = $fields['date_timezone'];
        }

        ksort($settings);

        return $settings;
    }

    /**
     * The settings a website runs with: the server's defaults with its own
     * applied, its upload and POST sizes no larger than the server allows.
     *
     * @param  array<string, string>|null  $settings
     * @param  array<string, string>  $defaults
     * @return array<string, string>
     */
    public static function effective(?array $settings, array $defaults, string $uploadLimit): array
    {
        $effective = [...$defaults, ...($settings ?? [])];

        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            if (self::megabytes($effective[$key]) > self::megabytes($uploadLimit)) {
                $effective[$key] = $uploadLimit;
            }
        }

        return $effective;
    }

    /**
     * The settings to send the agent for a website: those it runs with
     * that differ from what the agent assumes ({@see DEFAULTS}).
     *
     * @param  array<string, string>|null  $settings
     * @param  array<string, string>  $defaults
     * @return array<string, string>
     */
    public static function forAgent(?array $settings, array $defaults, string $uploadLimit): array
    {
        $settings = array_diff_assoc(self::effective($settings, $defaults, $uploadLimit), self::DEFAULTS);
        ksort($settings);

        return $settings;
    }

    /**
     * Megabytes of a size like "64M".
     */
    public static function megabytes(string $size): int
    {
        return (int) rtrim($size, 'M');
    }
}
