<?php

namespace App\Enums;

enum AccountStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Deleting = 'deleting';
    case Failed = 'failed';

    /**
     * Only fully provisioned, non-suspended accounts may sign in to the panel.
     */
    public function canLogIn(): bool
    {
        return $this === self::Active;
    }
}
