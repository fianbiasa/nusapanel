<?php

namespace App\Listeners;

use App\Enums\TaskStatus;
use App\Events\AgentTaskFinished;
use App\Models\Domain;
use App\Services\MailService;

/**
 * Settle the mail domains the agent prepared: they take mail once their
 * folder and DKIM key exist, and their DNS zone gets the mail records.
 */
class ApplyMailTaskResult
{
    public function __construct(private MailService $mail) {}

    public function handle(AgentTaskFinished $event): void
    {
        $task = $event->task;
        $name = $task->payload['domain'] ?? null;

        if ($task->action !== 'mail.domain_apply' || $task->user_id === null || ! is_string($name)) {
            return;
        }

        $mailDomain = Domain::query()
            ->where('user_id', $task->user_id)
            ->where('name', $name)
            ->first()
            ?->mailDomain;

        if ($mailDomain === null) {
            return;
        }

        match ($task->status) {
            TaskStatus::Success => $this->mail->completeDomainApply($mailDomain, is_array($task->output) ? $task->output : [], $task->actor),
            TaskStatus::Failed => $this->mail->failDomainApply($mailDomain),
            default => null,
        };
    }
}
