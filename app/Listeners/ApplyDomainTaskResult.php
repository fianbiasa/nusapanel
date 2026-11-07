<?php

namespace App\Listeners;

use App\Enums\DomainStatus;
use App\Enums\SslStatus;
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

        $domain = Domain::findForTask($task);

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

        if ($domain->ssl_status === SslStatus::Removing && ($task->payload['ssl'] ?? null) === false) {
            $this->continueSslRemoval($domain, $task);
        }
    }

    /**
     * Once the website is served without its certificate, the certificate
     * can be deleted. If the website could not be applied without it, it
     * still uses the certificate, which therefore stays active.
     */
    private function continueSslRemoval(Domain $domain, Task $task): void
    {
        if ($task->status === TaskStatus::Success) {
            $this->domains->deleteCertificate($domain, $task->actor);
        } elseif ($task->status === TaskStatus::Failed) {
            $domain->forceFill(['ssl_status' => SslStatus::Active])->save();
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
}
