<?php

namespace App\Listeners;

use App\Enums\DomainStatus;
use App\Enums\TaskStatus;
use App\Events\AgentTaskFinished;
use App\Models\Domain;
use App\Models\Task;
use App\Services\DomainService;

/**
 * Bring a domain's panel record in line with what the agent did on the
 * server. The task's error is shown in the task list.
 */
class ApplyDomainTaskResult
{
    public function __construct(private DomainService $domains) {}

    public function handle(AgentTaskFinished $event): void
    {
        $task = $event->task;

        if (! in_array($task->action, ['web.apply_domain', 'web.remove_domain'], true)) {
            return;
        }

        $domain = $this->findDomain($task);

        if ($domain === null) {
            return;
        }

        if ($task->action === 'web.apply_domain') {
            $this->applyApply($domain, $task);
        } else {
            $this->applyRemove($domain, $task);
        }
    }

    private function applyApply(Domain $domain, Task $task): void
    {
        if ($task->status === TaskStatus::Success
            && in_array($domain->status, [DomainStatus::Pending, DomainStatus::Failed], true)) {
            $domain->forceFill(['status' => DomainStatus::Active])->save();
        } elseif ($task->status === TaskStatus::Failed && $domain->status !== DomainStatus::Deleting) {
            $domain->forceFill(['status' => DomainStatus::Failed])->save();
        }
    }

    private function applyRemove(Domain $domain, Task $task): void
    {
        if ($domain->status !== DomainStatus::Deleting) {
            return;
        }

        if ($task->status === TaskStatus::Success) {
            $this->domains->completeRemoval($domain, $task->actor);
        } elseif ($task->status === TaskStatus::Failed) {
            $domain->forceFill(['status' => DomainStatus::Failed])->save();
        }
    }

    private function findDomain(Task $task): ?Domain
    {
        $name = $task->payload['domain'] ?? null;

        if ($task->user_id === null || ! is_string($name)) {
            return null;
        }

        return Domain::query()
            ->where('user_id', $task->user_id)
            ->where('name', $name)
            ->first();
    }
}
