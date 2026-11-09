<?php

namespace App\Enums;

/**
 * Where a database or database user stands on the server.
 */
enum DatabaseStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';
    case Deleting = 'deleting';

    /**
     * Only what is not waiting on the agent may be changed or deleted.
     */
    public function isSettled(): bool
    {
        return in_array($this, [self::Active, self::Failed], true);
    }
}
