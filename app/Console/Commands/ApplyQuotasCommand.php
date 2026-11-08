<?php

namespace App\Console\Commands;

use App\Services\QuotaService;
use Illuminate\Console\Command;

/**
 * Re-applies the disk quota of every hosting account. setup.sh runs it
 * after each upgrade, so accounts created before quotas existed (or before
 * they were enabled on the server) get the limits of their package.
 */
class ApplyQuotasCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nusapanel:apply-quotas';

    /**
     * @var string
     */
    protected $description = 'Queue the disk quota of every active or suspended hosting account again, e.g. after an upgrade';

    public function handle(QuotaService $quotas): int
    {
        $queued = $quotas->applyAll();

        $this->components->info($queued === 1
            ? '1 account queued for its disk quota.'
            : "{$queued} accounts queued for their disk quota.");

        return self::SUCCESS;
    }
}
