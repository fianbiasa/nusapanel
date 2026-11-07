<?php

namespace App\Listeners;

use App\Enums\SslStatus;
use App\Enums\TaskStatus;
use App\Events\AgentTaskFinished;
use App\Models\Domain;
use App\Models\Task;
use App\Services\DomainService;

/**
 * Bring a domain's certificate in line with what the agent did on the
 * server. Results that arrive after the domain's SSL status has moved on
 * are ignored.
 */
class ApplySslTaskResult
{
    public function __construct(private DomainService $domains) {}

    public function handle(AgentTaskFinished $event): void
    {
        $task = $event->task;

        if (! in_array($task->action, ['ssl.issue', 'ssl.delete'], true)) {
            return;
        }

        $domain = Domain::findForTask($task);

        if ($domain === null) {
            return;
        }

        if ($task->action === 'ssl.issue') {
            $this->applyIssue($domain, $task);
        } else {
            $this->applyDelete($domain, $task);
        }
    }

    /**
     * An issued certificate is recorded and the website switched to HTTPS;
     * a failed request keeps the agent's explanation, e.g. that the domain
     * does not point to this server yet.
     */
    private function applyIssue(Domain $domain, Task $task): void
    {
        if ($domain->ssl_status !== SslStatus::Pending) {
            return;
        }

        if ($task->status === TaskStatus::Success) {
            $this->domains->completeSslIssue($domain, $task->output ?? [], $task->actor);
        } elseif ($task->status === TaskStatus::Failed) {
            $domain->forceFill([
                'ssl_status' => SslStatus::Failed,
                'ssl_error' => $task->error,
            ])->save();
        }
    }

    /**
     * The website stopped using the certificate before it was deleted, so
     * the domain has no certificate either way. One the agent failed to
     * delete stays on the server and keeps renewing until the domain is
     * removed; the error remains in the task list.
     */
    private function applyDelete(Domain $domain, Task $task): void
    {
        if ($domain->ssl_status === SslStatus::Removing) {
            $this->domains->clearSsl($domain);
        }
    }
}
