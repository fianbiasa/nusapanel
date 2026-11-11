<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\BulkAccountAction;
use App\Enums\Role;
use App\Listeners\ApplyAccountTaskResult;
use App\Models\Package;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and changes hosting accounts. The panel record is updated right
 * away and the matching change on the server is queued for the agent;
 * {@see ApplyAccountTaskResult} applies the agent's result.
 */
class AccountService
{
    /**
     * The most accounts one bulk action may change.
     */
    public const MAX_BULK = 100;

    public function __construct(
        private TaskDispatcher $tasks,
        private AuditLogger $audit,
        private DomainService $domains,
        private QuotaService $quotas,
        private DnsService $dns,
        private MailService $mail,
    ) {}

    /**
     * Create a pending hosting account owned by the actor, on the given
     * package if any, and queue its creation on the server. A primary
     * domain, if given, must fit the package and is set up once the account
     * exists on the server.
     *
     * @param  array{username: string, name: string, email: string, password: string, domain?: string|null, package_id?: int|null}  $data
     *
     * @throws ValidationException When the primary domain cannot be used.
     */
    public function create(array $data, User $actor): User
    {
        return DB::transaction(function () use ($data, $actor): User {
            $account = new User(Arr::only($data, ['username', 'name', 'email', 'password']));

            $account->forceFill([
                'role' => Role::User,
                'status' => AccountStatus::Pending,
                'owner_id' => $actor->id,
                'package_id' => $data['package_id'] ?? null,
                'email_verified_at' => now(),
            ])->save();

            $this->tasks->dispatch('account.create', ['username' => $account->username], $account, $actor);

            $this->audit->record('account.created', $account, [
                'username' => $account->username,
                'role' => Role::User->value,
                'package' => $account->package?->name,
            ], $actor);

            $domain = $data['domain'] ?? null;

            if ($domain !== null && $domain !== '') {
                $this->domains->create($account, $domain, $actor, primary: true);
            }

            return $account;
        });
    }

    /**
     * Block the account from signing in right away and lock it on the server.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function suspend(User $account, ?string $reason, User $actor): void
    {
        $this->ensureCanBeChanged($account, $actor, 'suspend');
        $this->ensureStatus($account, [AccountStatus::Active], __('Only active accounts can be suspended.'));

        DB::transaction(function () use ($account, $reason, $actor): void {
            $account->forceFill([
                'status' => AccountStatus::Suspended,
                'suspend_reason' => $reason,
            ])->save();

            $this->tasks->dispatch('account.suspend', ['username' => $account->username], $account, $actor);

            $this->audit->record('account.suspended', $account, [
                'username' => $account->username,
                'reason' => $reason,
            ], $actor);
        });
    }

    /**
     * Let a suspended account sign in again and unlock it on the server.
     *
     * @throws ValidationException
     */
    public function unsuspend(User $account, User $actor): void
    {
        $this->ensureStatus($account, [AccountStatus::Suspended], __('Only suspended accounts can be unsuspended.'));

        DB::transaction(function () use ($account, $actor): void {
            $account->forceFill([
                'status' => AccountStatus::Active,
                'suspend_reason' => null,
            ])->save();

            $this->tasks->dispatch('account.unsuspend', ['username' => $account->username], $account, $actor);

            $this->audit->record('account.unsuspended', $account, ['username' => $account->username], $actor);
        });
    }

    /**
     * Mark the account for deletion and queue its removal from the server.
     * The panel record is deleted once the agent reports success.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function delete(User $account, User $actor): void
    {
        $this->ensureCanBeChanged($account, $actor, 'delete');
        $this->ensureStatus(
            $account,
            [AccountStatus::Active, AccountStatus::Suspended, AccountStatus::Failed],
            __('This account is still being created or is already being deleted.'),
        );

        DB::transaction(function () use ($account, $actor): void {
            $account->forceFill(['status' => AccountStatus::Deleting])->save();

            $this->dns->removeAccount($account, $actor);
            $this->mail->removeAccount($account, $actor);
            $this->tasks->dispatch('account.delete', ['username' => $account->username], $account, $actor);

            $this->audit->record('account.deleted', $account, ['username' => $account->username], $actor);
        });
    }

    /**
     * Move a hosting account to another package, or take it off packages so
     * nothing is limited. An account that already uses more than the new
     * package allows keeps what it has, but cannot add more. The account's
     * disk quota on the server follows the new package.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function changePackage(User $account, ?Package $package, User $actor): void
    {
        if (! $account->hasRole(Role::User)) {
            throw new AuthorizationException(__('Only hosting accounts can be put on a package.'));
        }

        $this->ensureStatus(
            $account,
            [AccountStatus::Pending, AccountStatus::Active, AccountStatus::Suspended, AccountStatus::Failed],
            __('This account is being deleted.'),
        );

        if ($account->package_id === $package?->id) {
            return;
        }

        $previous = $account->package;

        DB::transaction(function () use ($account, $package, $previous, $actor): void {
            $account->forceFill(['package_id' => $package?->id])->save();
            $account->setRelation('package', $package);

            $this->quotas->apply($account, $actor);

            $this->audit->record('account.package_changed', $account, [
                'username' => $account->username,
                'from' => $previous?->name,
                'to' => $package?->name,
            ], $actor);
        });
    }

    /**
     * Do the action to each of the accounts, one by
     * one, with the same checks as for a single account. An account the
     * action does not fit (an administrator, an account that is already
     * suspended, ...) is skipped with the reason, and the others still go
     * ahead.
     *
     * @param  list<int>  $ids
     * @return array{done: list<string>, skipped: array<string, string>} Usernames, and the reason each skipped one was left alone.
     */
    public function bulk(BulkAccountAction $action, array $ids, ?string $reason, ?Package $package, User $actor): array
    {
        $done = [];
        $skipped = [];

        $accounts = User::query()->whereKey($ids)->orderBy('username')->get();

        foreach ($accounts as $account) {
            try {
                match ($action) {
                    BulkAccountAction::Suspend => $this->suspend($account, $reason, $actor),
                    BulkAccountAction::Unsuspend => $this->unsuspend($account, $actor),
                    BulkAccountAction::Delete => $this->delete($account, $actor),
                    BulkAccountAction::Package => $this->changePackage($account, $package, $actor),
                };

                $done[] = $account->username;
            } catch (ValidationException $exception) {
                $skipped[$account->username] = (string) collect($exception->errors())->flatten()->first();
            } catch (AuthorizationException $exception) {
                $skipped[$account->username] = __($exception->getMessage());
            }
        }

        return ['done' => $done, 'skipped' => $skipped];
    }

    /**
     * Administrators, and the acting account itself, are never suspended or
     * deleted from the panel.
     *
     * @throws AuthorizationException
     */
    private function ensureCanBeChanged(User $account, User $actor, string $action): void
    {
        if ($account->is($actor)) {
            throw new AuthorizationException("You cannot {$action} your own account.");
        }

        if ($account->isAdmin()) {
            throw new AuthorizationException("You cannot {$action} an administrator account.");
        }
    }

    /**
     * @param  list<AccountStatus>  $allowed
     *
     * @throws ValidationException
     */
    private function ensureStatus(User $account, array $allowed, string $message): void
    {
        if (! in_array($account->status, $allowed, true)) {
            throw ValidationException::withMessages(['account' => $message]);
        }
    }
}
