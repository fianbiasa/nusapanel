<?php

namespace App\Console\Commands;

use App\Agent\AgentException;
use App\Services\DatabaseService;
use App\Services\QuotaService;
use Illuminate\Console\Command;

/**
 * Stores how much disk space and how many files every hosting account
 * uses, as the server's disk quotas report it, and the size of every
 * database. The scheduler runs it every fifteen minutes; the dashboard,
 * the account list and the Databases page show the result.
 */
class CollectUsageCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rayxo:collect-usage';

    /**
     * @var string
     */
    protected $description = 'Read the disk usage of every hosting account from the server agent';

    public function handle(QuotaService $quotas, DatabaseService $databases): int
    {
        // Database sizes are measured apart from the disk quotas, so one
        // failing does not stop the other.
        try {
            $sizes = $databases->collectSizes();
            $this->components->info($sizes === 1 ? 'Size of 1 database stored.' : "Size of {$sizes} databases stored.");
        } catch (AgentException $exception) {
            report($exception);

            $this->components->error('The server agent could not report database sizes: '.$exception->getMessage());
        }

        try {
            $measured = $quotas->collectUsage();
        } catch (AgentException $exception) {
            report($exception);

            $this->components->error('The server agent could not report disk usage: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (QuotaService::isEnabled() === false) {
            $this->components->warn('Disk quotas are not enabled on this server, so disk usage is not measured.');
        }

        $this->components->info($measured === 1
            ? 'Disk usage of 1 account stored.'
            : "Disk usage of {$measured} accounts stored.");

        return self::SUCCESS;
    }
}
