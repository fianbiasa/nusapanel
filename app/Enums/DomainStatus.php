<?php

namespace App\Enums;

enum DomainStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';
    case Deleting = 'deleting';

    /**
     * Only domains that are not waiting on the agent may be deleted.
     */
    public function canBeDeleted(): bool
    {
        return in_array($this, [self::Active, self::Failed], true);
    }
}
