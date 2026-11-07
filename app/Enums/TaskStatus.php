<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isFinished(): bool
    {
        return in_array($this, [self::Success, self::Failed, self::Cancelled], true);
    }
}
