<?php

namespace App\Listeners;

use App\Enums\TaskStatus;
use App\Events\AgentTaskFinished;
use App\Models\ResellerSetting;

/**
 * Mark a reseller's sign-in domain active once the agent serves the panel
 * there, or failed with the agent's reason. A domain changed meanwhile is
 * left alone.
 */
class ApplyLoginDomainTaskResult
{
    public function handle(AgentTaskFinished $event): void
    {
        $task = $event->task;
        $domain = $task->payload['domain'] ?? null;

        if ($task->action !== 'panel.add_login_domain' || ! is_string($domain) || $task->user_id === null) {
            return;
        }

        $settings = ResellerSetting::query()
            ->where('user_id', $task->user_id)
            ->where('login_domain', $domain)
            ->first();

        if ($settings === null) {
            return;
        }

        $settings->forceFill($task->status === TaskStatus::Success
            ? ['login_domain_status' => 'active', 'login_domain_error' => null]
            : ['login_domain_status' => 'failed', 'login_domain_error' => $task->error])->save();
    }
}
