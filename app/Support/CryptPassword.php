<?php

namespace App\Support;

/**
 * SHA512-CRYPT password hashes ($6$salt$hash), which the system's crypt()
 * checks: Pure-FTPd for FTP logins, and PAM for the SFTP password.
 */
final class CryptPassword
{
    /**
     * The hash of password, with a random 16-character salt.
     */
    public static function hash(string $password): string
    {
        $alphabet = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $salt = '';

        for ($i = 0; $i < 16; $i++) {
            $salt .= $alphabet[random_int(0, 63)];
        }

        return crypt($password, '$6$'.$salt.'$');
    }

    /**
     * Whether password is the password of hash.
     */
    public static function matches(string $password, string $hash): bool
    {
        return hash_equals($hash, crypt($password, $hash));
    }
}
