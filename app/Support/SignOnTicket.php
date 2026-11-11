<?php

namespace App\Support;

/**
 * The tickets the panel hands to the tools it signs customers in to
 * (phpMyAdmin, Roundcube): a JSON payload sealed with a key the tool shares
 * (XSalsa20-Poly1305), written as URL-safe base64 of the nonce followed by
 * the box. The tool's sign-on code opens it with the same key.
 */
final class SignOnTicket
{
    /**
     * The 32-byte key in a configured base64 value, or null.
     */
    public static function key(mixed $configured): ?string
    {
        $key = base64_decode((string) $configured, true);

        return is_string($key) && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? $key : null;
    }

    /**
     * Seal payload with key.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function seal(string $key, array $payload): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox(json_encode($payload, JSON_THROW_ON_ERROR), $nonce, $key);

        return sodium_bin2base64($nonce.$box, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }
}
