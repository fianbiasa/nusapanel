<?php

namespace App\Enums;

enum BackupRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    /** There was not enough free space; nothing was backed up. */
    case Skipped = 'skipped';
}
