<?php

namespace App\Jobs;

use App\Models\BackupRun;
use App\Services\ServerBackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Closes a run of the automatic backups once the backup of every account
 * in it had its turn on the backups queue.
 */
class FinishBackupRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public BackupRun $run)
    {
        $this->onQueue(ServerBackupService::QUEUE);
    }

    public function handle(ServerBackupService $backups): void
    {
        $backups->finish($this->run);
    }
}
