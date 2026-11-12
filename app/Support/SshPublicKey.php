<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * An OpenSSH public key as an account pastes it into the panel, checked as
 * the agent checks it again (agent/internal/sshkey): one line, without
 * options in front of it (a key from the panel cannot force a command or
 * open forwarding), of a type sshd accepts by default, and an RSA key of at
 * least 2048 bits.
 */
final class SshPublicKey
{
    public const MAX_LENGTH = 16384;

    public const MAX_COMMENT = 200;

    public const MIN_RSA_BITS = 2048;

    private const MAX_RSA_BITS = 16384;

    /**
     * The size of the public point of each ECDSA curve.
     */
    private const CURVES = ['nistp256' => 65, 'nistp384' => 97, 'nistp521' => 133];

    private function __construct(
        public readonly string $type,
        private readonly string $blob,
        public readonly string $comment,
    ) {}

    /**
     * Check one key line, "type base64 comment".
     *
     * @throws InvalidArgumentException With a message for the account.
     */
    public static function parse(string $line): self
    {
        $line = trim($line);

        if (str_contains($line, 'PRIVATE KEY')) {
            throw new InvalidArgumentException(__('This is a private key. Paste the public key instead: the .pub file.'));
        }

        if (strlen($line) > self::MAX_LENGTH || preg_match('/[\r\n\0]/', $line) === 1 || ! mb_check_encoding($line, 'UTF-8')) {
            throw new InvalidArgumentException(__('Paste one public key, on one line.'));
        }

        $fields = preg_split('/\s+/', $line) ?: [];
        $type = $fields[0] ?? '';

        if (count($fields) < 2 || ! self::isSupportedType($type)) {
            throw new InvalidArgumentException(__('This is not a public key this server accepts (ssh-ed25519, ecdsa-sha2-*, ssh-rsa or a security key), or it has options in front of it.'));
        }

        $blob = base64_decode($fields[1], true);

        if ($blob === false || base64_encode($blob) !== $fields[1] || ! self::isValidBlob($type, $blob)) {
            throw new InvalidArgumentException(__('The key is damaged: copy the whole line of the .pub file.'));
        }

        if ($type === 'ssh-rsa' && self::rsaBits($blob) < self::MIN_RSA_BITS) {
            throw new InvalidArgumentException(__('RSA keys need at least :bits bits. Make an ed25519 key instead: ssh-keygen -t ed25519', ['bits' => self::MIN_RSA_BITS]));
        }

        $comment = implode(' ', array_slice($fields, 2));

        if (mb_strlen($comment) > self::MAX_COMMENT || preg_match('/\p{Cc}/u', $comment) === 1) {
            throw new InvalidArgumentException(__('The comment of the key may have at most :max printable characters.', ['max' => self::MAX_COMMENT]));
        }

        return new self($type, $blob, $comment);
    }

    /**
     * The key as a line of authorized_keys.
     */
    public function line(): string
    {
        return rtrim($this->type.' '.base64_encode($this->blob).' '.$this->comment);
    }

    /**
     * The fingerprint ssh-keygen -l and ssh show: SHA256 of the key, in
     * base64 without padding.
     */
    public function fingerprint(): string
    {
        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $this->blob, true)), '=');
    }

    private static function isSupportedType(string $type): bool
    {
        return in_array($type, ['ssh-ed25519', 'sk-ssh-ed25519@openssh.com', 'ssh-rsa', 'sk-ecdsa-sha2-nistp256@openssh.com'], true)
            || preg_match('/^ecdsa-sha2-nistp(256|384|521)$/', $type) === 1;
    }

    /**
     * Whether the decoded key holds what its type says, and nothing else.
     */
    private static function isValidBlob(string $type, string $blob): bool
    {
        $offset = 0;

        if (self::readString($blob, $offset) !== $type) {
            return false;
        }

        $securityKey = str_starts_with($type, 'sk-');
        $keyType = $securityKey ? str_replace(['sk-', '@openssh.com'], '', $type) : $type;

        $valid = match (true) {
            $keyType === 'ssh-ed25519' => self::readsBytes($blob, $offset, 32),
            str_starts_with($keyType, 'ecdsa-sha2-') => self::isValidPoint($keyType, $blob, $offset),
            default => self::rsaBits($blob) > 0 && self::skipRsa($blob, $offset),
        };

        if ($valid && $securityKey) {
            $application = self::readString($blob, $offset);
            $valid = $application !== null && str_starts_with($application, 'ssh:');
        }

        return $valid && $offset === strlen($blob);
    }

    /**
     * @param-out int $offset
     */
    private static function isValidPoint(string $keyType, string $blob, int &$offset): bool
    {
        $curve = substr($keyType, strlen('ecdsa-sha2-'));
        $size = self::CURVES[$curve] ?? 0;

        if (self::readString($blob, $offset) !== $curve) {
            return false;
        }

        $point = self::readString($blob, $offset);

        return $point !== null && strlen($point) === $size && str_starts_with($point, "\x04");
    }

    /**
     * @param-out int $offset
     */
    private static function skipRsa(string $blob, int &$offset): bool
    {
        $exponent = self::readString($blob, $offset);
        $modulus = self::readString($blob, $offset);

        return $exponent !== null && $modulus !== null;
    }

    /**
     * Whether the next string of the key has exactly length bytes.
     *
     * @param-out int $offset
     */
    private static function readsBytes(string $blob, int &$offset, int $length): bool
    {
        $value = self::readString($blob, $offset);

        return $value !== null && strlen($value) === $length;
    }

    /**
     * The size of an RSA key's modulus in bits, or 0 when the key is
     * damaged or larger than sshd allows.
     */
    private static function rsaBits(string $blob): int
    {
        $offset = 0;
        self::readString($blob, $offset);
        $exponent = self::readString($blob, $offset);
        $modulus = self::readString($blob, $offset);

        if ($exponent === null || $exponent === '' || strlen($exponent) > 8 || $modulus === null || $modulus === '' || ord($modulus[0]) >= 0x80) {
            return 0;
        }

        $modulus = ltrim($modulus, "\0");

        if ($modulus === '') {
            return 0;
        }

        $bits = (strlen($modulus) - 1) * 8 + strlen(decbin(ord($modulus[0])));

        return $bits <= self::MAX_RSA_BITS ? $bits : 0;
    }

    /**
     * Read one string of the SSH wire format, a 4-byte big-endian length and
     * that many bytes, or null when the key ends before it does. Each call
     * moves offset past what it read.
     *
     * @param-out int $offset
     *
     * @phpstan-impure
     */
    private static function readString(string $blob, int &$offset): ?string
    {
        if (strlen($blob) - $offset < 4) {
            return null;
        }

        $length = (ord($blob[$offset]) << 24) | (ord($blob[$offset + 1]) << 16) | (ord($blob[$offset + 2]) << 8) | ord($blob[$offset + 3]);

        if ($length > strlen($blob) - $offset - 4) {
            return null;
        }

        $value = substr($blob, $offset + 4, $length);
        $offset += 4 + $length;

        return $value;
    }
}
