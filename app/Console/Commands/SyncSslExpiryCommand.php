<?php

namespace App\Console\Commands;

use App\Agent\AgentException;
use App\Services\CertificateExpiryService;
use Illuminate\Console\Command;

/**
 * Stores the end dates of Let's Encrypt certificates certbot renewed on
 * its own, so the Domains page shows when the renewed certificate ends.
 * The scheduler runs it every day.
 */
class SyncSslExpiryCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rayxo:sync-ssl-expiry';

    /**
     * @var string
     */
    protected $description = "Read the end dates of renewed Let's Encrypt certificates from the server agent";

    public function handle(CertificateExpiryService $certificates): int
    {
        try {
            $updated = $certificates->sync();
        } catch (AgentException $exception) {
            report($exception);

            $this->components->error('The server agent could not report when certificates end: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info($updated === 1
            ? 'New end date stored for 1 certificate.'
            : "New end date stored for {$updated} certificates.");

        return self::SUCCESS;
    }
}
