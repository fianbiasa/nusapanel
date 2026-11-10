<?php

namespace App\Console\Commands;

use App\Services\DomainService;
use App\Services\PhpVersionService;
use Illuminate\Console\Command;

/**
 * Re-applies the web server configuration of every website. setup.sh runs
 * it after each upgrade, so websites set up by an older release pick up
 * changed templates (new locations, HTTPS, security fixes). It also forgets
 * the cached PHP versions, so versions the upgrade installed can be chosen
 * right away.
 */
class RebuildWebCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rayxo:rebuild-web';

    /**
     * @var string
     */
    protected $description = 'Queue the web server configuration of every active website again, e.g. after an upgrade';

    public function handle(DomainService $domains, PhpVersionService $phpVersions): int
    {
        $phpVersions->forget();

        $queued = $domains->reapplyAll();

        $this->components->info($queued === 1
            ? '1 website queued for reconfiguration.'
            : "{$queued} websites queued for reconfiguration.");

        return self::SUCCESS;
    }
}
