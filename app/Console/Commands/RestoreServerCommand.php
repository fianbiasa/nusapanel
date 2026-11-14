<?php

namespace App\Console\Commands;

use App\Services\ServerRestoreService;
use Illuminate\Console\Command;

/**
 * Makes the accounts, databases and configuration in the panel's database
 * on this server again, after the panel was restored from a backup of
 * itself (see ServerRestoreService). setup.sh --restore-system runs it
 * while the queue workers are stopped.
 */
class RestoreServerCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rayxo:restore-server
                            {--system-backup= : The file name of the backup of the panel restored from, which was still being made when its database was dumped}';

    /**
     * @var string
     */
    protected $description = 'Queue the accounts, databases and configuration in the panel database on this server again, after a restore of the panel';

    public function handle(ServerRestoreService $restore): int
    {
        $queued = $restore->recreate($this->option('system-backup') ?: null);

        $this->components->info('Queued: '.implode(', ', [
            "{$queued['accounts']} accounts",
            "{$queued['databases']} databases",
            "{$queued['websites']} websites",
            "{$queued['dns_zones']} DNS zones",
            "{$queued['mail_domains']} mail domains",
        ]).'. The queue worker makes them in the background; see Tasks in the panel.');

        return self::SUCCESS;
    }
}
