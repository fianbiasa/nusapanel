<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Support\CryptPassword;
use Illuminate\Validation\ValidationException;

/**
 * Sets the SFTP password of hosting accounts: the password of their Linux
 * user, which sshd only lets them use for SFTP. It is separate from the
 * panel password, and only its SHA512-CRYPT hash leaves the panel, in the
 * (encrypted) payload of the account.set_password task. The panel keeps
 * when it was set, not the password or its hash.
 */
class SftpService
{
    public function __construct(
        private TaskDispatcher $tasks,
        private AuditLogger $audit,
    ) {}

    /**
     * Give the account a new SFTP password, or none (null): it can then only
     * log in with an SSH key, if it put one in ~/.ssh/authorized_keys.
     *
     * @throws ValidationException
     */
    public function setPassword(User $account, ?string $password, User $actor): void
    {
        if (! $account->hasRole(Role::User) || $account->status !== AccountStatus::Active) {
            throw ValidationException::withMessages([
                'password' => __('The SFTP password can only be changed while the account is active.'),
            ]);
        }

        $this->tasks->dispatch('account.set_password', [
            'username' => $account->username,
            'password_hash' => $password === null ? '' : CryptPassword::hash($password),
        ], $account, $actor);

        $account->forceFill(['sftp_password_set_at' => $password === null ? null : now()])->save();

        $this->audit->record(
            $password === null ? 'account.sftp_password_removed' : 'account.sftp_password_set',
            $account,
            ['username' => $account->username],
            $actor,
        );
    }

    /**
     * Where the account's latest SFTP password change stands: whether the
     * agent is still applying it, and the error when it failed.
     *
     * @return array{pending: bool, error: string|null}
     */
    public function syncState(User $account): array
    {
        $task = Task::query()
            ->where('user_id', $account->id)
            ->where('action', 'account.set_password')
            ->latest('created_at')
            ->latest('id')
            ->first();

        return [
            'pending' => in_array($task?->status, [TaskStatus::Queued, TaskStatus::Running], true),
            'error' => $task?->status === TaskStatus::Failed ? ($task->error ?? '') : null,
        ];
    }
}
