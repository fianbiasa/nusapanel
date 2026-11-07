<?php

namespace App\Listeners;

use App\Enums\AccountStatus;
use App\Enums\TaskStatus;
use App\Events\AgentTaskFinished;
use App\Models\Task;
use App\Models\User;
use App\Services\DomainService;

/**
 * Bring an account's panel record in line with what the agent did on the
 * server. Failed suspend/unsuspend tasks leave the account untouched; the
 * task's error is shown in the task list instead.
 *
 * Domains added while the account was being created are set up once it
 * exists on the server, or fail with it.
 */
class ApplyAccountTaskResult
{
    public function __construct(private DomainService $domains) {}

    public function handle(AgentTaskFinished $event): void
    {
        $task = $event->task;
        $account = $task->account;

        if ($account === null) {
            return;
        }

        if ($task->action === 'account.create') {
            $this->applyCreate($account, $task);
        } elseif ($task->action === 'account.delete') {
            $this->applyDelete($account, $task);
        }
    }

    private function applyCreate(User $account, Task $task): void
    {
        if ($account->status !== AccountStatus::Pending) {
            return;
        }

        if ($task->status === TaskStatus::Failed) {
            $account->forceFill(['status' => AccountStatus::Failed])->save();

            $this->domains->failPendingDomains($account);

            return;
        }

        if ($task->status !== TaskStatus::Success) {
            return;
        }

        $uid = filter_var($task->output['uid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        $account->forceFill([
            'status' => AccountStatus::Active,
            'uid' => $uid === false ? $account->uid : $uid,
        ])->save();

        $this->domains->dispatchPendingDomains($account, $task->actor);
    }

    private function applyDelete(User $account, Task $task): void
    {
        if ($account->status !== AccountStatus::Deleting) {
            return;
        }

        if ($task->status === TaskStatus::Success) {
            $account->delete();
        } elseif ($task->status === TaskStatus::Failed) {
            $account->forceFill(['status' => AccountStatus::Failed])->save();
        }
    }
}
