<?php

namespace App\Listeners;

use App\Enums\AccountStatus;
use App\Enums\TaskStatus;
use App\Events\AgentTaskFinished;
use App\Models\Task;
use App\Models\User;
use App\Services\AccountRestoreService;
use App\Services\DomainService;
use App\Services\QuotaService;
use App\Services\TicketService;

/**
 * Bring an account's panel record in line with what the agent did on the
 * server. Failed suspend/unsuspend tasks leave the account untouched; the
 * task's error is shown in the task list instead.
 *
 * Once a new account exists on the server, it gets the disk quota of its
 * package and the domains added while it was being created are set up;
 * those domains fail with it when it could not be created. An account made
 * again from a backup then gets the rest of it back.
 */
class ApplyAccountTaskResult
{
    public function __construct(
        private DomainService $domains,
        private QuotaService $quotas,
        private TicketService $tickets,
        private AccountRestoreService $restores,
    ) {}

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
            $this->restores->accountFailed($account);

            return;
        }

        if ($task->status !== TaskStatus::Success) {
            return;
        }

        $uid = filter_var($task->output['uid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $gid = filter_var($task->output['gid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        $account->forceFill([
            'status' => AccountStatus::Active,
            'uid' => $uid === false ? $account->uid : $uid,
            'gid' => $gid === false ? $account->gid : $gid,
        ])->save();

        $this->quotas->applyToNewAccount($account, $task->actor);

        $this->domains->dispatchPendingDomains($account, $task->actor);

        $this->restores->resume($account);
    }

    private function applyDelete(User $account, Task $task): void
    {
        if ($account->status !== AccountStatus::Deleting) {
            return;
        }

        if ($task->status === TaskStatus::Success) {
            $this->tickets->deleteAttachmentsOf($account);
            $account->delete();
        } elseif ($task->status === TaskStatus::Failed) {
            $account->forceFill(['status' => AccountStatus::Failed])->save();
        }
    }
}
