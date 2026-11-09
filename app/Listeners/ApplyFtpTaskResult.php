<?php

namespace App\Listeners;

use App\Enums\FtpAccountStatus;
use App\Enums\TaskStatus;
use App\Events\AgentTaskFinished;
use App\Models\Task;
use App\Models\User;

/**
 * Settle the FTP logins whose folder the agent prepared: they can sign in
 * once it exists. The agent also reports the account's Linux ids, which the
 * logins act as; accounts created before FTP only learn their group id here.
 */
class ApplyFtpTaskResult
{
    public function handle(AgentTaskFinished $event): void
    {
        $task = $event->task;
        $directory = $task->payload['directory'] ?? null;

        if ($task->action !== 'ftp.ensure_dir' || $task->user_id === null || ! is_string($directory)) {
            return;
        }

        $account = User::query()->find($task->user_id);

        if ($account === null) {
            return;
        }

        if ($task->status === TaskStatus::Success) {
            $this->rememberIds($account, $task);
        }

        $status = match ($task->status) {
            TaskStatus::Success => FtpAccountStatus::Active,
            TaskStatus::Failed => FtpAccountStatus::Failed,
            default => null,
        };

        if ($status === null) {
            return;
        }

        $account->ftpAccounts()
            ->where('status', FtpAccountStatus::Pending)
            ->where('directory', $directory)
            ->update(['status' => $status]);
    }

    private function rememberIds(User $account, Task $task): void
    {
        $options = ['options' => ['min_range' => 0]];
        $uid = filter_var($task->output['uid'] ?? null, FILTER_VALIDATE_INT, $options);
        $gid = filter_var($task->output['gid'] ?? null, FILTER_VALIDATE_INT, $options);

        $account->forceFill([
            'uid' => $uid === false ? $account->uid : $uid,
            'gid' => $gid === false ? $account->gid : $gid,
        ])->save();
    }
}
