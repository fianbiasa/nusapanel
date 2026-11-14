<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Services\ServerBackupService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Starts the automatic backups when their schedule says so, and closes a
 * run that stopped moving. The scheduler runs it every hour, on the hour.
 */
class RunBackupsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rayxo:run-backups
                            {--now : Back up every account now, whatever the schedule says}';

    /**
     * @var string
     */
    protected $description = 'Start the automatic backups of every account when they are due';

    public function handle(ServerBackupService $backups): int
    {
        $backups->closeStale();

        if (! $this->option('now') && ($backups->running() !== null || ! $backups->due(CarbonImmutable::now()))) {
            return self::SUCCESS;
        }

        try {
            $run = $backups->start($this->option('now') ? BackupRun::TRIGGER_MANUAL : BackupRun::TRIGGER_SCHEDULE);
        } catch (ValidationException $exception) {
            $this->components->error((string) collect($exception->errors())->flatten()->first());

            return self::FAILURE;
        }

        $this->components->info($run->message ?? "Backing up {$run->accounts} account(s) (run {$run->id}).");

        return self::SUCCESS;
    }
}
