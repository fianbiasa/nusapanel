<?php

namespace App\Support;

use App\Enums\Role;

/**
 * The alerts the panel sends, with the roles that may get them. A hosting
 * account and its reseller hear about their own accounts; administrators
 * about the whole server.
 */
final class NotificationEvents
{
    /** A service stopped and the watchdog restarted it, or could not. */
    public const SERVICE_FAILED = 'service_failed';

    /** The server's disk is almost full. */
    public const DISK_FULL = 'disk_full';

    /** An account or website reached its disk or bandwidth limit. */
    public const QUOTA_EXCEEDED = 'quota_exceeded';

    /** A certificate could not be issued, or ends within days. */
    public const SSL_FAILED = 'ssl_failed';

    /** A ticket was opened or answered. */
    public const TICKET = 'ticket';

    /** Automatic backups were skipped for lack of space, or some failed. */
    public const BACKUP = 'backup';

    /**
     * @var array<string, list<Role>>
     */
    public const ALL = [
        self::SERVICE_FAILED => [Role::Admin],
        self::DISK_FULL => [Role::Admin],
        self::QUOTA_EXCEEDED => [Role::Admin, Role::Reseller, Role::User],
        self::SSL_FAILED => [Role::Admin, Role::Reseller, Role::User],
        self::TICKET => [Role::Admin, Role::Reseller, Role::User],
        self::BACKUP => [Role::Admin],
    ];

    /**
     * The alerts an account with role may get.
     *
     * @return list<string>
     */
    public static function for(Role $role): array
    {
        return array_keys(array_filter(self::ALL, fn (array $roles): bool => in_array($role, $roles, true)));
    }
}
