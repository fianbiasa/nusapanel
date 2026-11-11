<?php

namespace App\Console\Commands;

use App\Services\MailSendLimitService;
use Illuminate\Console\Command;

/**
 * Gives the server the daily send limits and which domains belong to which
 * account, when they changed since it last got them. The scheduler runs it
 * every five minutes, so new domains are limited too; setup.sh runs it after
 * each install or upgrade.
 */
class SyncSendLimitsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rayxo:sync-send-limits';

    /**
     * @var string
     */
    protected $description = 'Queue the daily send limits for the mail server when they changed';

    public function handle(MailSendLimitService $sendLimits): int
    {
        $task = $sendLimits->sync();

        $this->components->info($task === null
            ? 'The mail server already has the current send limits.'
            : 'Send limits queued. The queue worker applies them in the background; see Tasks in the panel.');

        return self::SUCCESS;
    }
}
