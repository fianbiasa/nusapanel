<?php

namespace App\Console\Commands;

use App\Services\DomainLimitService;
use Illuminate\Console\Command;

/**
 * Measures the folders of websites that have a disk limit and stops those
 * over it, or lets them run again. The scheduler runs it every hour.
 */
class MeasureDomainDiskCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rayxo:measure-domain-disk';

    /**
     * @var string
     */
    protected $description = 'Measure the folders of websites with a disk limit and apply the limit';

    public function handle(DomainLimitService $limits): int
    {
        $changed = $limits->measureDisk();

        $this->components->info("Websites stopped or started again by their disk limit: {$changed}.");

        return self::SUCCESS;
    }
}
