<?php

namespace App\Services;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Models\Mailbox;

/**
 * How much mail each mailbox holds, read from Dovecot's quota bookkeeping
 * through the agent (mail.usage), for mailboxes with a quota and without.
 * rayxo:collect-usage stores it every fifteen minutes; the Email page shows
 * it.
 */
class MailUsageService
{
    public function __construct(private AgentClient $agent) {}

    /**
     * Store every mailbox's size and message count.
     *
     * @return int How many mailboxes were measured.
     *
     * @throws AgentException
     */
    public function collect(): int
    {
        $report = $this->agent->call('mail.usage', [], (int) config('agent.timeout'));

        $usages = [];

        foreach ((array) ($report['mailboxes'] ?? []) as $usage) {
            if (is_array($usage) && is_string($usage['address'] ?? null)) {
                $usages[strtolower($usage['address'])] = [
                    'used_kb' => max(0, (int) ($usage['used_kb'] ?? 0)),
                    'message_count' => max(0, (int) ($usage['messages'] ?? 0)),
                ];
            }
        }

        $measured = 0;

        Mailbox::query()->with('mailDomain.domain')->chunkById(500, function ($mailboxes) use ($usages, &$measured): void {
            foreach ($mailboxes as $mailbox) {
                $usage = $usages[$mailbox->local_part.'@'.$mailbox->mailDomain->domain->name] ?? null;

                if ($usage === null) {
                    continue;
                }

                $mailbox->forceFill($usage)->save();
                $measured++;
            }
        });

        return $measured;
    }
}
