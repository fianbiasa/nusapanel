<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Account usernames double as Linux user names, so they must be short,
 * lowercase, and must not collide with system or service accounts.
 */
class ValidUsername implements ValidationRule
{
    public const PATTERN = '/^[a-z][a-z0-9]{2,15}$/';

    /**
     * Names used by the operating system and the services the panel runs.
     */
    public const RESERVED = [
        'adm', 'apache', 'backup', 'bin', 'clamav', 'daemon', 'dnsmasq', 'dovecot', 'dovenull',
        'ftp', 'games', 'gnats', 'irc', 'landscape', 'list', 'lp', 'lxd', 'mail', 'man',
        'messagebus', 'mysql', 'news', 'nginx', 'nobody', 'nusad', 'nusapanel', 'operator',
        'pdns', 'polkitd', 'pollinate', 'postfix', 'proxy', 'pureftpd', 'redis', 'root',
        'rspamd', 'sshd', 'staff', 'sudo', 'sync', 'sys', 'syslog', 'systemd', 'tcpdump',
        'tss', 'ubuntu', 'users', 'uucp', 'uuidd', 'vmail',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            $fail('The :attribute must be 3 to 16 characters long, using only lowercase letters and digits, and start with a letter.');

            return;
        }

        if (in_array($value, self::RESERVED, true)) {
            $fail('The :attribute is reserved by the system.');
        }
    }
}
