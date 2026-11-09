<?php

namespace App\Listeners;

use App\Enums\SslStatus;
use App\Enums\TaskStatus;
use App\Events\AgentTaskFinished;
use App\Models\Domain;
use App\Models\Task;
use App\Services\DomainService;
use Illuminate\Support\Arr;

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

        if (! in_array($task->action, ['ssl.issue', 'ssl.install', 'ssl.delete'], true)) {
            return;
        }

        if ($task->action === 'ssl.install') {
            $this->forgetCertificate($task);
        }

        $domain = Domain::findForTask($task);

        if ($domain === null) {
            return;
        }

        match ($task->action) {
            'ssl.issue' => $this->applyIssue($domain, $task),
            'ssl.install' => $this->applyInstall($domain, $task),
            default => $this->applyDelete($domain, $task),
        };
    }

    /**
     * A custom certificate is now on the server (or was refused), so the
     * task no longer needs its private key: only the agent ever reads it.
     */
    private function forgetCertificate(Task $task): void
    {
        $task->forceFill([
            'payload' => [
                ...Arr::except($task->payload ?? [], ['certificate', 'private_key']),
                'certificate' => '[removed]',
                'private_key' => '[removed]',
            ],
        ])->save();
    }

    /**
     * An installed custom certificate is recorded and the website served
     * with it; a refused one keeps the agent's explanation, and a website
     * that had a certificate keeps it.
     */
    private function applyInstall(Domain $domain, Task $task): void
    {
        if (! in_array($domain->ssl_status, [SslStatus::Pending, SslStatus::Replacing], true)) {
            return;
        }

        if ($task->status === TaskStatus::Success) {
            $this->domains->completeCustomInstall($domain, $task->output ?? [], $task->actor);
        } elseif ($task->status === TaskStatus::Failed) {
            $this->domains->failCustomInstall($domain, $task->error);
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
