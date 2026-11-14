<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Services\AccountTransferService;
use App\Services\CpanelImportService;
use App\Services\ServerBackupService;
use App\Services\SystemBackupService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Starts the automatic backups when their schedule says so, and closes a
 * run that stopped moving; backs up the panel itself once a day. The
 * scheduler runs it every hour, on the hour.
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

    public function handle(ServerBackupService $backups, SystemBackupService $system, AccountTransferService $transfers, CpanelImportService $imports): int
    {
        $backups->closeStale();
        $transfers->closeStale();
        $imports->closeStale();

        // The panel itself is backed up every day, apart from the accounts.
        if ($system->due()) {
            $system->start();
        }

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
