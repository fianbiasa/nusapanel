<?php

namespace App\Console\Commands;

use App\Services\DnsService;
use Illuminate\Console\Command;

/**
 * Gives every domain and pointer its DNS zone (from the template when it
 * has none yet) and sends every zone to the server again. setup.sh runs it
 * after each install or upgrade, so domains added before DNS existed get
 * their zones too.
 */
class SyncDnsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rayxo:sync-dns';

    /**
     * @var string
     */
    protected $description = 'Give every domain its DNS zone and queue every zone for the DNS server again';

    public function handle(DnsService $dns): int
    {
        $queued = $dns->syncAll();

        $this->components->info(($queued === 1 ? '1 DNS zone queued.' : "{$queued} DNS zones queued.").' The queue worker applies them in the background; see Tasks in the panel.');

        return self::SUCCESS;
    }
}
