<?php

namespace App\Console\Commands;

use App\Agent\AgentException;
use App\Services\ResourceUsageService;
use Illuminate\Console\Command;

/**
 * Reads the CPU time and memory hosting accounts use from their cgroups
 * and adds them to today's. The scheduler runs it every five minutes, so
 * a day's memory peak is the most of its five-minute samples.
 */
class CollectResourcesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rayxo:collect-resources';

    /**
     * @var string
     */
    protected $description = 'Read the CPU time and memory of hosting accounts from the server';

    public function handle(ResourceUsageService $resources): int
    {
        try {
            $measured = $resources->collect();
        } catch (AgentException $exception) {
            report($exception);

            $this->components->error('The server agent could not read the accounts\' CPU and memory: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("CPU and memory read for {$measured} accounts.");

        return self::SUCCESS;
    }
}
