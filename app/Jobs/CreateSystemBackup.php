<?php

namespace App\Jobs;

use App\Models\SystemBackup;
use App\Services\ServerBackupService;
use App\Services\SystemBackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * A backup of the panel itself, on the backups queue.
 */
class CreateSystemBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600 + 300;

    public bool $failOnTimeout = true;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public SystemBackup $backup)
    {
        $this->onQueue(ServerBackupService::QUEUE);
    }

    public function handle(SystemBackupService $backups): void
    {
        $backups->create($this->backup);
    }

    public function failed(?Throwable $exception): void
    {
        app(SystemBackupService::class)->fail($this->backup, __('The backup did not finish.'));
    }
}
