<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\SshKey;
use App\Models\Task;
use App\Models\User;
use App\Support\AccountLimits;
use App\Support\SshPublicKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * SSH for hosting accounts. Every account has SFTP; an account whose
 * package allows SSH may turn on a shell, which the agent runs in a jail of
 * the account's own (account.set_ssh). The public keys an account adds go to
 * its ~/.ssh/authorized_keys (account.ssh_keys) and work for both. It logs
 * in with its SFTP password or one of the keys.
 */
class SshService
{
    /**
     * How many keys an account may add, as the agent allows.
     */
    public const MAX_KEYS = 20;

    public function __construct(
        private TaskDispatcher $tasks,
        private AuditLogger $audit,
    ) {}

    /**
     * Turn SSH on or off for the account. Turning it on needs a package
     * that includes SSH.
     *
     * @throws ValidationException
     */
    public function setEnabled(User $account, bool $enabled, User $actor): void
    {
        $this->ensureActive($account, 'enabled');

        if ($enabled && ! AccountLimits::for($account)->allowsSsh()) {
            throw ValidationException::withMessages(['enabled' => __('SSH is not included in your package.')]);
        }

        if ($account->ssh_enabled === $enabled) {
            return;
        }

        DB::transaction(function () use ($account, $enabled, $actor): void {
            $account->forceFill(['ssh_enabled' => $enabled])->save();

            $this->sync($account, $actor);

            $this->audit->record($enabled ? 'account.ssh_enabled' : 'account.ssh_disabled', $account, [
                'username' => $account->username,
            ], $actor);
        });
    }

    /**
     * Whether the account has a shell now: it turned SSH on, its package
     * allows it and it is active.
     */
    public function isActive(User $account): bool
    {
        return $account->hasRole(Role::User)
            && $account->status === AccountStatus::Active
            && $account->ssh_enabled
            && AccountLimits::for($account)->allowsSsh();
    }

    /**
     * Queue the account's SSH access as it should be now.
     */
    public function sync(User $account, ?User $actor): void
    {
        $this->tasks->dispatch('account.set_ssh', [
            'username' => $account->username,
            'enabled' => $this->isActive($account),
        ], $account, $actor);
    }

    /**
     * Queue again the SSH access of the accounts the query selects that
     * turned SSH on, after whether they may have it could have changed
     * (their package or their reseller's package). Suspended accounts have
     * no shell, and get theirs back when they are unsuspended.
     *
     * @param  Builder<User>  $accounts
     */
    public function reapply(Builder $accounts, ?User $actor): void
    {
        $accounts->clone()
            ->where('role', Role::User)
            ->where('ssh_enabled', true)
            ->where('status', AccountStatus::Active)
            ->with(['package', 'owner.resellerPackage'])
            ->each(fn (User $account) => $this->sync($account, $actor));
    }

    /**
     * Add a public key to the account.
     *
     * @throws ValidationException
     */
    public function addKey(User $account, string $name, string $publicKey, User $actor): SshKey
    {
        $this->ensureActive($account, 'public_key');

        try {
            $key = SshPublicKey::parse($publicKey);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['public_key' => $exception->getMessage()]);
        }

        return DB::transaction(function () use ($account, $name, $key, $actor): SshKey {
            // Locks the account, so two keys added at once both count.
            User::query()->whereKey($account->id)->lockForUpdate()->first();

            if ($account->sshKeys()->where('fingerprint', $key->fingerprint())->exists()) {
                throw ValidationException::withMessages(['public_key' => __('This key is added already.')]);
            }

            if ($account->sshKeys()->count() >= self::MAX_KEYS) {
                throw ValidationException::withMessages(['public_key' => __('An account may have at most :max keys.', ['max' => self::MAX_KEYS])]);
            }

            $sshKey = $account->sshKeys()->create([
                'name' => $name,
                'public_key' => $key->line(),
                'fingerprint' => $key->fingerprint(),
            ]);

            $this->syncKeys($account, $actor);

            $this->audit->record('ssh_key.added', $sshKey, [
                'username' => $account->username,
                'name' => $name,
                'fingerprint' => $key->fingerprint(),
            ], $actor);

            return $sshKey;
        });
    }

    /**
     * Remove a key from the account; it no longer logs in once the agent
     * has written the account's keys again.
     *
     * @throws ValidationException
     */
    public function removeKey(SshKey $sshKey, User $actor): void
    {
        $account = $sshKey->account;

        $this->ensureActive($account, 'public_key');

        DB::transaction(function () use ($account, $sshKey, $actor): void {
            $sshKey->delete();

            $this->syncKeys($account, $actor);

            $this->audit->record('ssh_key.removed', $sshKey, [
                'username' => $account->username,
                'name' => $sshKey->name,
                'fingerprint' => $sshKey->fingerprint,
            ], $actor);
        });
    }

    /**
     * Where the account's latest SSH change stands: whether the agent is
     * still applying it, and the error when it failed.
     *
     * @return array{pending: bool, error: string|null}
     */
    public function syncState(User $account): array
    {
        $task = Task::query()
            ->where('user_id', $account->id)
            ->whereIn('action', ['account.set_ssh', 'account.ssh_keys'])
            ->latest('created_at')
            ->latest('id')
            ->first();

        return [
            'pending' => in_array($task?->status, [TaskStatus::Queued, TaskStatus::Running], true),
            'error' => $task?->status === TaskStatus::Failed ? ($task->error ?? '') : null,
        ];
    }

    /**
     * Queue writing every key of the account to its authorized_keys.
     */
    private function syncKeys(User $account, User $actor): void
    {
        $this->tasks->dispatch('account.ssh_keys', [
            'username' => $account->username,
            'keys' => $account->sshKeys()->orderBy('id')->pluck('public_key')->all(),
        ], $account, $actor);
    }

    /**
     * @throws ValidationException
     */
    private function ensureActive(User $account, string $field): void
    {
        if (! $account->hasRole(Role::User) || $account->status !== AccountStatus::Active) {
            throw ValidationException::withMessages([
                $field => __('SSH can only be changed while the account is active.'),
            ]);
        }
    }
}
