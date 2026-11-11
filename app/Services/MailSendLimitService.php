<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\Task;
use App\Models\User;

/**
 * The daily send limits of hosting accounts. Rspamd applies them to the mail
 * an account sends (signed in on 587/465, or through the local sendmail of
 * its Linux user), counting recipients per 24 hours: a message over the
 * limit is refused for now (451), so it waits and is tried again later
 * rather than lost. The agent writes what Rspamd needs into a map: the
 * limits from the server settings and which domains belong to which
 * account, which follows every new or removed domain within minutes
 * (rayxo:sync-send-limits runs every five).
 */
class MailSendLimitService
{
    public function __construct(
        private ServerSettingsService $settings,
        private TaskDispatcher $tasks,
    ) {}

    /**
     * What the agent's mail.send_limits_apply writes, 0 being no limit.
     *
     * @return array{account_limit: int, server_limit: int, accounts: list<array{username: string, domains: list<string>}>, mailboxes: list<array{address: string, limit: int}>}
     */
    public function payload(): array
    {
        $limits = $this->settings->mailSendLimits();

        $accounts = [];

        $users = User::query()
            ->where('role', Role::User)
            ->orderBy('username')
            ->with(['domains' => fn ($query) => $query->orderBy('name')])
            ->get();

        foreach ($users as $account) {
            $accounts[] = [
                'username' => $account->username,
                'domains' => array_values($account->domains->map(fn (Domain $domain): string => $domain->name)->all()),
            ];
        }

        // Mailboxes with their own limit, for what they send signed in.
        $mailboxes = [];

        $limited = Mailbox::query()
            ->whereNotNull('daily_send_limit')
            ->with('mailDomain.domain')
            ->get()
            ->sortBy(fn (Mailbox $mailbox): string => $mailbox->local_part.'@'.$mailbox->mailDomain->domain->name);

        foreach ($limited as $mailbox) {
            $mailboxes[] = [
                'address' => $mailbox->local_part.'@'.$mailbox->mailDomain->domain->name,
                'limit' => (int) $mailbox->daily_send_limit,
            ];
        }

        return [
            'account_limit' => $limits['account'] ?? 0,
            'server_limit' => $limits['server'] ?? 0,
            'accounts' => $accounts,
            'mailboxes' => $mailboxes,
        ];
    }

    /**
     * Give the server the current limits and domains, unless it already has
     * them (the latest such task has the same payload and did not fail).
     * force sends them anyway, for rayxo rebuild.
     */
    public function sync(?User $actor = null, bool $force = false): ?Task
    {
        $payload = $this->payload();

        $latest = Task::query()
            ->where('action', 'mail.send_limits_apply')
            ->latest('id')
            ->first();

        if (! $force && $latest !== null && $latest->status !== TaskStatus::Failed && $latest->payload == $payload) {
            return null;
        }

        return $this->tasks->dispatch('mail.send_limits_apply', $payload, null, $actor);
    }
}
