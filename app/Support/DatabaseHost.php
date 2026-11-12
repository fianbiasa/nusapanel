<?php

namespace App\Support;

/**
 * A host besides the server that database users may connect from, in the
 * form MariaDB keeps it, as the agent checks it again: an IPv4 or IPv6
 * address, an IPv4 network as address/netmask, an IPv4 prefix ending in a
 * wildcard (192.168.1.%), or % for any host. Host names are not allowed:
 * every login would wait on DNS.
 */
final class DatabaseHost
{
    /**
     * The most hosts one account may allow, as the agent's limit.
     */
    public const MAX_PER_ACCOUNT = 20;

    private const OCTET = '(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])';

    /**
     * The stored form of what someone typed, or null when it is not a host
     * an account may allow. CIDR notation (192.168.1.0/24) becomes the
     * address/netmask MariaDB understands, for the network the address is
     * in.
     */
    public static function normalize(string $input): ?string
    {
        $host = strtolower(trim($input));

        if ($host === '%' || preg_match('/^(?:'.self::OCTET.'\.){1,3}%$/D', $host) === 1) {
            return $host;
        }

        if (str_contains($host, '/')) {
            return self::network($host);
        }

        if (str_contains($host, '%')) {
            return null;
        }

        $packed = @inet_pton($host);
        $canonical = $packed === false ? false : inet_ntop($packed);

        if ($canonical === false || $canonical === '0.0.0.0' || $canonical === '::' || $canonical === '::1'
            || str_starts_with($canonical, '127.') || str_starts_with($canonical, '::ffff:')) {
            return null;
        }

        return $canonical;
    }

    /**
     * An IPv4 network in CIDR or address/netmask notation, as
     * network/netmask.
     */
    private static function network(string $host): ?string
    {
        [$address, $mask] = explode('/', $host, 2);

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        if (preg_match('/^\d{1,2}$/D', $mask) === 1) {
            $bits = (int) $mask;

            if ($bits < 1 || $bits > 32) {
                return null;
            }

            $maskValue = (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;
        } elseif (filter_var($mask, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $maskValue = (int) ip2long($mask);
            $inverted = ~$maskValue & 0xFFFFFFFF;

            if ($maskValue === 0 || ($inverted & ($inverted + 1)) !== 0) {
                return null;
            }
        } else {
            return null;
        }

        $network = (int) ip2long($address) & $maskValue;

        if ($maskValue === 0xFFFFFFFF) {
            return self::normalize((string) long2ip($network));
        }

        return long2ip($network).'/'.long2ip($maskValue);
    }
}
