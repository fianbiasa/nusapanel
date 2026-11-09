<?php

namespace App\Enums;

/**
 * Where an FTP login stands. Only active logins can sign in; a login waits
 * (pending) while the agent prepares its folder.
 */
enum FtpAccountStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';

    /**
     * Only what is not waiting on the agent may be changed.
     */
    public function isSettled(): bool
    {
        return $this !== self::Pending;
    }
}
