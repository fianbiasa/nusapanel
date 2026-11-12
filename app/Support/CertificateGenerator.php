<?php

namespace App\Support;

use OpenSSLAsymmetricKey;
use OpenSSLCertificateSigningRequest;
use RuntimeException;

/**
 * Makes a new private key with a certificate signing request (CSR) for a
 * certificate authority, or with a self-signed certificate, for host names
 * of a website. Names must already be valid domain names (or *.<domain>):
 * they are written into an OpenSSL configuration file.
 */
final class CertificateGenerator
{
    /**
     * The keys that can be made: RSA 2048 or 4096 bits, or ECDSA P-256.
     */
    public const KEY_TYPES = ['rsa2048', 'rsa4096', 'ec256'];

    /**
     * The organization fields of the subject, besides the common name,
     * with their names in a certificate.
     */
    public const SUBJECT_FIELDS = [
        'organization' => 'organizationName',
        'organizational_unit' => 'organizationalUnitName',
        'locality' => 'localityName',
        'state' => 'stateOrProvinceName',
        'country' => 'countryName',
        'email' => 'emailAddress',
    ];

    /**
     * The longest a self-signed certificate may be valid, in days.
     */
    public const MAX_DAYS = 3650;

    /**
     * A new key and a CSR for names; the first name is the common name.
     *
     * @param  non-empty-list<string>  $names
     * @param  array<string, string>  $subject  {@see self::SUBJECT_FIELDS} that were filled in.
     * @return array{key: string, csr: string}
     */
    public static function request(array $names, array $subject, string $keyType): array
    {
        return self::withConfig($names, $keyType, function (string $config) use ($names, $subject, $keyType): array {
            $key = self::newKey($keyType, $config);
            $csr = self::newCsr($names, $subject, $key, $config);

            if (! openssl_csr_export($csr, $csrPem)) {
                throw new RuntimeException('The certificate signing request could not be exported.');
            }

            return ['key' => self::exportKey($key, $config), 'csr' => $csrPem];
        });
    }

    /**
     * A new key and a certificate for names signed by that key, valid from
     * now for days.
     *
     * @param  non-empty-list<string>  $names
     * @param  array<string, string>  $subject  {@see self::SUBJECT_FIELDS} that were filled in.
     * @return array{key: string, certificate: string}
     */
    public static function selfSigned(array $names, array $subject, string $keyType, int $days): array
    {
        return self::withConfig($names, $keyType, function (string $config) use ($names, $subject, $keyType, $days): array {
            $key = self::newKey($keyType, $config);
            $csr = self::newCsr($names, $subject, $key, $config);

            $certificate = openssl_csr_sign($csr, null, $key, $days, [
                'config' => $config,
                'digest_alg' => 'sha256',
                'x509_extensions' => 'rayxo_cert',
            ], random_int(1, PHP_INT_MAX));

            if ($certificate === false || ! openssl_x509_export($certificate, $certificatePem)) {
                throw new RuntimeException('The certificate could not be signed.');
            }

            return ['key' => self::exportKey($key, $config), 'certificate' => $certificatePem];
        });
    }

    /**
     * Run callback with an OpenSSL configuration that asks for names, in a
     * temporary file that is removed afterwards.
     *
     * @template T
     *
     * @param  non-empty-list<string>  $names
     * @param  callable(string): T  $callback
     * @return T
     */
    private static function withConfig(array $names, string $keyType, callable $callback): mixed
    {
        $alternativeNames = implode(',', array_map(fn (string $name): string => "DNS:{$name}", $names));
        // An ECDSA key signs; an RSA key may also encrypt the session key.
        $keyUsage = str_starts_with($keyType, 'ec') ? 'digitalSignature' : 'digitalSignature, keyEncipherment';

        $config = tempnam(sys_get_temp_dir(), 'rayxo-openssl-');

        if ($config === false) {
            throw new RuntimeException('No temporary file for the OpenSSL configuration.');
        }

        file_put_contents($config, implode("\n", [
            '[req]',
            'distinguished_name = rayxo_dn',
            'string_mask = utf8only',
            '[rayxo_dn]',
            '[rayxo_req]',
            "subjectAltName = {$alternativeNames}",
            '[rayxo_cert]',
            "subjectAltName = {$alternativeNames}",
            'basicConstraints = critical, CA:FALSE',
            "keyUsage = critical, {$keyUsage}",
            'extendedKeyUsage = serverAuth',
            'subjectKeyIdentifier = hash',
            '',
        ]));

        try {
            return $callback($config);
        } finally {
            @unlink($config);
        }
    }

    private static function newKey(string $keyType, string $config): OpenSSLAsymmetricKey
    {
        $options = match ($keyType) {
            'rsa2048' => ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048],
            'rsa4096' => ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 4096],
            'ec256' => ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'],
            default => throw new RuntimeException("Unknown key type {$keyType}."),
        };

        $key = openssl_pkey_new(['config' => $config, ...$options]);

        if ($key === false) {
            throw new RuntimeException('The private key could not be made.');
        }

        return $key;
    }

    /**
     * @param  non-empty-list<string>  $names
     * @param  array<string, string>  $subject
     */
    private static function newCsr(array $names, array $subject, OpenSSLAsymmetricKey $key, string $config): OpenSSLCertificateSigningRequest
    {
        $distinguishedName = ['commonName' => $names[0]];

        foreach (self::SUBJECT_FIELDS as $field => $name) {
            if (($subject[$field] ?? '') !== '') {
                $distinguishedName[$name] = $subject[$field];
            }
        }

        $csr = openssl_csr_new($distinguishedName, $key, [
            'config' => $config,
            'digest_alg' => 'sha256',
            'req_extensions' => 'rayxo_req',
        ]);

        if (! $csr instanceof OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('The certificate signing request could not be made.');
        }

        return $csr;
    }

    private static function exportKey(OpenSSLAsymmetricKey $key, string $config): string
    {
        if (! openssl_pkey_export($key, $pem, null, ['config' => $config])) {
            throw new RuntimeException('The private key could not be exported.');
        }

        return $pem;
    }
}
