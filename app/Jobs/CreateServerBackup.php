<?php

namespace App\Jobs;

use App\Models\ServerBackup;
use App\Services\ServerBackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * The backup of one account in a run of the automatic backups.
 */
class CreateServerBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = ServerBackupService::BACKUP_SECONDS + 300;

    public bool $failOnTimeout = true;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public ServerBackup $backup, public bool $withDatabases)
    {
        $this->onQueue(ServerBackupService::QUEUE);
    }

    public function handle(ServerBackupService $backups): void
    {
        $backups->create($this->backup, $this->withDatabases);
    }

    public function failed(?Throwable $exception): void
    {
        app(ServerBackupService::class)->fail($this->backup, __('The backup did not finish.'));
    }
}
