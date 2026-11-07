<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Reseller = 'reseller';
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Reseller => 'Reseller',
            self::User => 'User',
        };
    }
}
