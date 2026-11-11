<?php

namespace App\Support;

/**
 * Checks and normalizes a DNS record as a customer types it, with the same
 * rules the agent applies before a record reaches PowerDNS (agent
 * internal/dns): names are typed relative to the zone ("@" or empty for the
 * zone itself, "www", "*.blog") and stored absolute without the trailing
 * dot; contents are stored in their usual, unquoted form.
 */
final class DnsRecordInput
{
    /**
     * The record types a customer may add. The SOA and the zone's own NS
     * records come from the server's nameservers.
     */
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'NS'];

    /**
     * The types that have a priority.
     */
    public const WITH_PRIORITY = ['MX', 'SRV'];

    public const MIN_TTL = 60;

    public const MAX_TTL = 604800;

    /**
     * The longest TXT value, in bytes (a 4096-bit DKIM key fits).
     */
    public const MAX_TXT = 4000;

    /**
     * The most records one zone may have.
     */
    public const MAX_RECORDS = 1000;

    private const LABEL_PATTERN = '/^[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?$/';

    private const CAA_VALUE_PATTERN = '/^[\x21\x23-\x5b\x5d-\x7e]{1,255}$/';

    private const CAA_TAGS = ['issue', 'issuewild', 'iodef'];

    /**
     * The absolute name of a typed name: "@" or empty is the zone, a name
     * that already ends in the zone stays as it is, anything else goes in
     * front of the zone. Lowercase, without dots around it.
     */
    public static function absoluteName(string $typed, string $zone): string
    {
        $name = strtolower(trim(trim($typed), '.'));

        if ($name === '' || $name === '@' || $name === $zone) {
            return $zone;
        }

        return str_ends_with($name, '.'.$zone) ? $name : $name.'.'.$zone;
    }

    /**
     * The name as the customer sees it: "@" for the zone itself, else the
     * part in front of the zone.
     */
    public static function relativeName(string $absolute, string $zone): string
    {
        return $absolute === $zone ? '@' : substr($absolute, 0, -strlen('.'.$zone));
    }

    /**
     * The content in its stored form: trimmed, and host names lowercase
     * without the trailing dot.
     */
    public static function normalizeContent(string $type, string $content): string
    {
        $content = trim($content);

        return match ($type) {
            'CNAME', 'NS', 'MX' => strtolower(rtrim($content, '.')),
            'AAAA' => strtolower($content),
            'SRV' => self::normalizeSrv($content),
            default => $content,
        };
    }

    /**
     * What is wrong with a record of zone, by field: name, content or
     * priority. Empty when it can be saved. Expects a normalized name and
     * content.
     *
     * @return array<string, string>
     */
    public static function errors(string $zone, string $name, string $type, string $content, ?int $priority): array
    {
        $errors = [];

        $nameError = self::nameError($zone, $name);

        if ($nameError !== null) {
            $errors['name'] = $nameError;
        }

        $isApex = $name === $zone;

        if ($isApex && $type === 'CNAME') {
            $errors['name'] = __('The domain itself cannot be a CNAME; use an A record.');
        }

        if ($isApex && $type === 'NS') {
            $errors['name'] = __("The domain's own nameservers are the server's; NS records are for subdomains.");
        }

        if (in_array($type, self::WITH_PRIORITY, true) && ($priority === null || $priority < 0 || $priority > 65535)) {
            $errors['priority'] = __('The priority must be a number from 0 to 65535.');
        }

        $contentError = self::contentError($type, $content);

        if ($contentError !== null) {
            $errors['content'] = $contentError;
        }

        return $errors;
    }

    private static function nameError(string $zone, string $name): ?string
    {
        if ($name === $zone) {
            return null;
        }

        if (strlen($name) > 253 || ! str_ends_with($name, '.'.$zone)) {
            return __('The name must be a name in :zone.', ['zone' => $zone]);
        }

        $labels = explode('.', substr($name, 0, -strlen('.'.$zone)));

        foreach ($labels as $index => $label) {
            if ($label === '*' && $index === 0) {
                continue;
            }

            if (preg_match(self::LABEL_PATTERN, $label) !== 1) {
                return __('Use letters, digits, dashes and underscores between dots; "*" only at the start.');
            }
        }

        return null;
    }

    private static function contentError(string $type, string $content): ?string
    {
        switch ($type) {
            case 'A':
                if (filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    return __('Enter an IPv4 address, such as 203.0.113.10.');
                }

                return null;
            case 'AAAA':
                if (filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false || str_contains($content, '.')) {
                    return __('Enter an IPv6 address, such as 2001:db8::10.');
                }

                return null;
            case 'CNAME':
            case 'NS':
            case 'MX':
                if (! self::isHost($content)) {
                    return __('Enter a host name, such as mail.example.com.');
                }

                return null;
            case 'TXT':
                return self::txtError($content);
            case 'SRV':
                return self::srvError($content);
            case 'CAA':
                return self::caaError($content);
        }

        return __('Choose a record type.');
    }

    private static function isHost(string $host): bool
    {
        $labels = explode('.', $host);

        if ($host === '' || strlen($host) > 253 || count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if (preg_match(self::LABEL_PATTERN, $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    private static function txtError(string $content): ?string
    {
        if ($content === '' || strlen($content) > self::MAX_TXT || ! mb_check_encoding($content, 'UTF-8')) {
            return __('The text must be 1 to :max bytes.', ['max' => self::MAX_TXT]);
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $content) === 1) {
            return __('The text must be on one line, without control characters.');
        }

        return null;
    }

    private static function srvError(string $content): ?string
    {
        $fields = explode(' ', $content);
        $message = __('Enter "weight port target", such as 5 5060 sip.example.com.');

        if (count($fields) !== 3 || ! self::isHost($fields[2])) {
            return $message;
        }

        foreach ([$fields[0], $fields[1]] as $number) {
            if (preg_match('/^(0|[1-9][0-9]{0,4})$/', $number) !== 1 || (int) $number > 65535) {
                return $message;
            }
        }

        return null;
    }

    private static function caaError(string $content): ?string
    {
        $fields = explode(' ', $content, 3);

        if (count($fields) !== 3
            || preg_match('/^(0|[1-9][0-9]{0,2})$/', $fields[0]) !== 1 || (int) $fields[0] > 255
            || ! in_array($fields[1], self::CAA_TAGS, true)
            || preg_match(self::CAA_VALUE_PATTERN, $fields[2]) !== 1) {
            return __('Enter "flags tag value", such as 0 issue letsencrypt.org (without quotes).');
        }

        return null;
    }

    /**
     * SRV content with single spaces and the target host lowercase,
     * without the trailing dot.
     */
    private static function normalizeSrv(string $content): string
    {
        $fields = preg_split('/\s+/', $content) ?: [];

        if (count($fields) === 3) {
            $fields[2] = strtolower(rtrim($fields[2], '.'));
        }

        return implode(' ', $fields);
    }
}
