<?php

namespace App\Support;

/**
 * A rough reading of a User-Agent header, good enough to recognise one's
 * own logins: the browser and the operating system, or null when unknown.
 */
final class UserAgent
{
    /**
     * Browsers, most specific first: Edge and Opera also say Chrome, and
     * Chrome also says Safari.
     *
     * @var array<string, string>
     */
    private const BROWSERS = [
        '/\bEdg(?:e|A|iOS)?\//' => 'Edge',
        '/\b(?:OPR|Opera)\//' => 'Opera',
        '/\bSamsungBrowser\//' => 'Samsung Internet',
        '/\b(?:Firefox|FxiOS)\//' => 'Firefox',
        '/\b(?:Chrome|CriOS|Chromium)\//' => 'Chrome',
        '/\bVersion\/[\d.]+.*\bSafari\//' => 'Safari',
        '/^curl\//' => 'curl',
    ];

    /**
     * Operating systems, most specific first: iPadOS and iOS also say
     * "like Mac OS X", and Android also says Linux.
     *
     * @var array<string, string>
     */
    private const SYSTEMS = [
        '/\biPad\b/' => 'iPadOS',
        '/\biPhone\b/' => 'iOS',
        '/\bAndroid\b/' => 'Android',
        '/\bWindows\b/' => 'Windows',
        '/\bMac OS X\b|\bMacintosh\b/' => 'macOS',
        '/\bCrOS\b/' => 'ChromeOS',
        '/\bLinux\b/' => 'Linux',
    ];

    /**
     * @return array{browser: string|null, os: string|null}
     */
    public static function describe(?string $userAgent): array
    {
        $userAgent ??= '';

        return [
            'browser' => self::first(self::BROWSERS, $userAgent),
            'os' => self::first(self::SYSTEMS, $userAgent),
        ];
    }

    /**
     * @param  array<string, string>  $patterns
     */
    private static function first(array $patterns, string $userAgent): ?string
    {
        foreach ($patterns as $pattern => $name) {
            if (preg_match($pattern, $userAgent) === 1) {
                return $name;
            }
        }

        return null;
    }
}
