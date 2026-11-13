<?php

namespace App\Support;

use App\Enums\Role;

/**
 * What a login key may do through the API, by area and read or write. A
 * key never does more than its account: an administrator's or reseller's
 * keys manage hosting accounts, a hosting account's keys its own websites.
 */
final class LoginKeyPermissions
{
    /**
     * Every permission, with the roles whose keys may hold it.
     *
     * @var array<string, list<Role>>
     */
    public const ALL = [
        'accounts:read' => [Role::Admin, Role::Reseller],
        'accounts:write' => [Role::Admin, Role::Reseller],
        'packages:read' => [Role::Admin, Role::Reseller],
        'domains:read' => [Role::User],
        'domains:write' => [Role::User],
        'databases:read' => [Role::User],
        'databases:write' => [Role::User],
        'email:read' => [Role::User],
        'email:write' => [Role::User],
        'dns:read' => [Role::User],
        'dns:write' => [Role::User],
    ];

    /**
     * The permissions a key of an account with role may hold.
     *
     * @return list<string>
     */
    public static function for(Role $role): array
    {
        return array_keys(array_filter(self::ALL, fn (array $roles): bool => in_array($role, $roles, true)));
    }
}
