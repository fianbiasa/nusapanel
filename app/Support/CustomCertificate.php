<?php

namespace App\Support;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

/**
 * Checks a certificate a customer brings for one of their domains, with
 * the same rules the agent applies before installing it: PEM certificates
 * (the domain's own first, then intermediates) and one unencrypted RSA
 * (2048 bits or more) or ECDSA (P-256, P-384, P-521) private key that
 * belongs to the first certificate, which covers the domain and is valid
 * now. The agent checks again; this only gives an answer right away.
 *
 * The private key is only ever held in memory.
 */
final class CustomCertificate
{
    public const MAX_CERTIFICATE_BYTES = 65536;

    public const MAX_KEY_BYTES = 16384;

    public const MAX_CHAIN_LENGTH = 10;

    private const CURVES = ['prime256v1', 'secp384r1', 'secp521r1'];

    /**
     * @param  list<string>  $names  The host names the certificate covers.
     * @param  int  $expiresAt  Unix timestamp.
     */
    private function __construct(
        public readonly array $names,
        public readonly int $expiresAt,
    ) {}

    /**
     * Check the certificate and key for domain at time now, a Unix
     * timestamp. Returns the certificate, or why it cannot be used: an
     * error message for the `certificate` or the `private_key` field.
     *
     * @return self|array{certificate: string}|array{private_key: string}
     */
    public static function inspect(string $domain, string $certificatePem, string $keyPem, int $now): self|array
    {
        $chain = self::parseChain($certificatePem);

        if (is_string($chain)) {
            return ['certificate' => $chain];
        }

        $key = self::parseKey($keyPem);

        if (is_string($key)) {
            return ['private_key' => $key];
        }

        $leaf = $chain[0];

        if (! openssl_x509_check_private_key($leaf, $key)) {
            return ['private_key' => self::message('The private key does not belong to the certificate.')];
        }

        $info = openssl_x509_parse($leaf);

        if ($info === false) {
            return ['certificate' => self::message('Certificate :number cannot be read.', ['number' => 1])];
        }

        $names = self::names($info);

        if (! self::covers($names, $domain)) {
            return ['certificate' => self::message('The certificate does not cover :domain.', ['domain' => $domain])];
        }

        $validFrom = (int) ($info['validFrom_time_t'] ?? 0);
        $validTo = (int) ($info['validTo_time_t'] ?? 0);

        if ($now < $validFrom) {
            return ['certificate' => self::message('The certificate is only valid from :date.', ['date' => gmdate('Y-m-d', $validFrom)])];
        }

        if ($now > $validTo) {
            return ['certificate' => self::message('The certificate expired on :date.', ['date' => gmdate('Y-m-d', $validTo)])];
        }

        return new self($names, $validTo);
    }

    /**
     * @return list<OpenSSLCertificate>|string
     */
    private static function parseChain(string $pem): array|string
    {
        if (strlen($pem) > self::MAX_CERTIFICATE_BYTES) {
            return self::message('The certificate is larger than :size KB.', ['size' => self::MAX_CERTIFICATE_BYTES >> 10]);
        }

        $pem = trim($pem);
        $chain = [];

        while ($pem !== '') {
            if (preg_match('/\A-----BEGIN ([A-Z0-9 ]+)-----\r?\n.*?-----END \1-----/s', $pem, $match) !== 1) {
                return self::message('The certificate must be in PEM format, starting with -----BEGIN CERTIFICATE-----.');
            }

            if ($match[1] !== 'CERTIFICATE') {
                return self::message('The certificate field may only hold certificates; the private key goes in its own field.');
            }

            $certificate = openssl_x509_read($match[0]);

            if ($certificate === false) {
                return self::message('Certificate :number cannot be read.', ['number' => count($chain) + 1]);
            }

            $chain[] = $certificate;
            $pem = trim(substr($pem, strlen($match[0])));
        }

        if ($chain === []) {
            return self::message('The certificate must be in PEM format, starting with -----BEGIN CERTIFICATE-----.');
        }

        if (count($chain) > self::MAX_CHAIN_LENGTH) {
            return self::message('The chain may have at most :count certificates.', ['count' => self::MAX_CHAIN_LENGTH]);
        }

        return $chain;
    }

    private static function parseKey(string $pem): OpenSSLAsymmetricKey|string
    {
        if (strlen($pem) > self::MAX_KEY_BYTES) {
            return self::message('The private key is larger than :size KB.', ['size' => self::MAX_KEY_BYTES >> 10]);
        }

        $pem = trim($pem);

        if (str_contains($pem, 'ENCRYPTED')) {
            return self::message('The private key is protected by a password; remove the password first.');
        }

        if (substr_count($pem, '-----BEGIN ') !== 1
            || preg_match('/\A-----BEGIN (RSA |EC |)PRIVATE KEY-----\r?\n.*?-----END \1PRIVATE KEY-----\z/s', $pem) !== 1) {
            return self::message('The private key must be one key in PEM format, starting with -----BEGIN PRIVATE KEY-----.');
        }

        $key = openssl_pkey_get_private($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        if ($key === false || $details === false) {
            return self::message('The private key cannot be read.');
        }

        if ($details['type'] === OPENSSL_KEYTYPE_RSA) {
            return $details['bits'] < 2048
                ? self::message('The RSA key has :bits bits; at least 2048 are needed.', ['bits' => $details['bits']])
                : $key;
        }

        if ($details['type'] === OPENSSL_KEYTYPE_EC) {
            return in_array($details['ec']['curve_name'] ?? null, self::CURVES, true)
                ? $key
                : self::message('The ECDSA key must use P-256, P-384 or P-521.');
        }

        return self::message('Only RSA and ECDSA keys are supported.');
    }

    /**
     * The DNS names of the certificate, or its common name without any.
     *
     * @param  array<string, mixed>  $info  What openssl_x509_parse() returned.
     * @return list<string>
     */
    private static function names(array $info): array
    {
        $alternative = $info['extensions']['subjectAltName'] ?? '';
        $names = [];

        foreach (explode(',', is_string($alternative) ? $alternative : '') as $entry) {
            $entry = trim($entry);

            if (str_starts_with($entry, 'DNS:')) {
                $names[] = strtolower(substr($entry, 4));
            }
        }

        if ($names === [] && is_string($info['subject']['CN'] ?? null)) {
            $names[] = strtolower($info['subject']['CN']);
        }

        return $names;
    }

    /**
     * Whether one of names is domain, or a wildcard for exactly one label
     * in front of the rest of it.
     *
     * @param  list<string>  $names
     */
    private static function covers(array $names, string $domain): bool
    {
        $dot = strpos($domain, '.');

        foreach ($names as $name) {
            if ($name === $domain) {
                return true;
            }

            if ($dot !== false && str_starts_with($name, '*.') && substr($domain, $dot) === substr($name, 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The translation of message, which is always a single line.
     *
     * @param  array<string, int|string>  $replace
     */
    private static function message(string $message, array $replace = []): string
    {
        $translated = __($message, $replace);

        return is_string($translated) ? $translated : $message;
    }
}
