<?php

namespace App\Console\Commands;

use App\Agent\AgentException;
use App\Services\BandwidthService;
use Illuminate\Console\Command;
use Illuminate\Support\Number;

/**
 * Adds up the traffic of hosting accounts and websites since the last
 * count, from the server's logs, and applies the bandwidth limits of
 * packages: lifting last month's bandwidth suspensions, and suspending
 * accounts that went over this month when that is turned on. The
 * scheduler runs it every fifteen minutes.
 */
class CollectBandwidthCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rayxo:collect-bandwidth';

    /**
     * @var string
     */
    protected $description = 'Count the bandwidth of hosting accounts from the server logs';

    public function handle(BandwidthService $bandwidth): int
    {
        try {
            $added = $bandwidth->collect();
        } catch (AgentException $exception) {
            report($exception);

            $this->components->error('The server agent could not count bandwidth: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Bandwidth counted: '.Number::fileSize($added, precision: 1).'.');

        ['suspended' => $suspended, 'unsuspended' => $unsuspended] = $bandwidth->enforceLimits();

        if ($suspended > 0 || $unsuspended > 0) {
            $this->components->info("Suspended for bandwidth: {$suspended}; suspension lifted for a new month: {$unsuspended}.");
        }

        return self::SUCCESS;
    }
}
