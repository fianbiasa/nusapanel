<?php

namespace App\Console\Commands;

use App\Services\MailRulesService;
use Illuminate\Console\Command;

/**
 * Writes the Sieve script of every mailbox with its own filters, automatic
 * reply or spam filter again. setup.sh runs it after each install or
 * upgrade, so scripts written by an older release follow the current
 * rules (such as spam being handled first).
 */
class ApplyMailRulesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rayxo:apply-mail-rules';

    /**
     * @var string
     */
    protected $description = 'Queue the Sieve script of every mailbox with filters, an automatic reply or a spam filter';

    public function handle(MailRulesService $rules): int
    {
        $queued = $rules->reapplyAll();

        $this->components->info(($queued === 1 ? '1 mailbox queued.' : "{$queued} mailboxes queued.").' The queue worker applies them in the background; see Tasks in the panel.');

        return self::SUCCESS;
    }
}
