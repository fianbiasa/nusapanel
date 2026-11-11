<?php

namespace App\Console\Commands;

use App\Services\RebuildService;
use Illuminate\Console\Command;

/**
 * Writes the whole server configuration again from the database (see
 * RebuildService). On a server, root runs it as "rayxo rebuild".
 */
class RebuildCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rayxo:rebuild';

    /**
     * @var string
     */
    protected $description = 'Queue the configuration of every website, quota, crontab, FTP folder and DNS zone again, from the database';

    public function handle(RebuildService $rebuild): int
    {
        $queued = $rebuild->queueAll();

        $this->components->info('Queued: '.implode(', ', [
            "{$queued['websites']} websites",
            "{$queued['quotas']} disk quotas",
            "{$queued['crontabs']} crontabs",
            "{$queued['ftp_folders']} FTP folders",
            "{$queued['dns_zones']} DNS zones",
            "{$queued['mail_domains']} mail domains",
        ]).'. The queue worker applies them in the background; see Tasks in the panel.');

        return self::SUCCESS;
    }
}
