<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\BulkAccountAction;
use App\Enums\Role;
use App\Listeners\ApplyAccountTaskResult;
use App\Models\Package;
use App\Models\ResellerPackage;
use App\Models\User;
use App\Support\ResellerLimits;
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
            $package = isset($data['package_id']) ? Package::query()->findOrFail($data['package_id']) : null;

            $this->ensurePackageBelongsTo($package, $actor);
            $this->ensureOwnerCanGiveOut($actor, $package);

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
     * Create a reseller or administrator. They live only in the panel (no
     * Linux user, nothing on the server), so they are active right away. A
     * reseller may get a reseller package that limits what it gives out.
     *
     * @param  array{username: string, name: string, email: string, password: string}  $data
     */
    public function createPanelAccount(array $data, Role $role, ?ResellerPackage $package, User $actor): User
    {
        return DB::transaction(function () use ($data, $role, $package, $actor): User {
            $account = new User(Arr::only($data, ['username', 'name', 'email', 'password']));

            $account->forceFill([
                'role' => $role,
                'status' => AccountStatus::Active,
                'owner_id' => $actor->id,
                'reseller_package_id' => $role === Role::Reseller ? $package?->id : null,
                'email_verified_at' => now(),
            ])->save();

            $this->audit->record('account.created', $account, array_filter([
                'username' => $account->username,
                'role' => $role->value,
                'reseller_package' => $role === Role::Reseller ? $package?->name : null,
            ], fn (mixed $value): bool => $value !== null), $actor);

            return $account;
        });
    }

    /**
     * Put a reseller on another reseller package, or take it off packages
     * so nothing it gives out is limited. A reseller that already gives out
     * more than the new package allows keeps its accounts, but cannot add
     * more.
     *
     * @throws AuthorizationException
     */
    public function changeResellerPackage(User $reseller, ?ResellerPackage $package, User $actor): void
    {
        if (! $reseller->isReseller()) {
            throw new AuthorizationException(__('Only resellers can be put on a reseller package.'));
        }

        if ($reseller->reseller_package_id === $package?->id) {
            return;
        }

        $previous = $reseller->resellerPackage;

        DB::transaction(function () use ($reseller, $package, $previous, $actor): void {
            $reseller->forceFill(['reseller_package_id' => $package?->id])->save();
            $reseller->setRelation('resellerPackage', $package);

            $this->domains->reapplyCgiWebsites(User::query()->where('owner_id', $reseller->id), $actor);

            $this->audit->record('account.reseller_package_changed', $reseller, [
                'username' => $reseller->username,
                'from' => $previous?->name,
                'to' => $package?->name,
            ], $actor);
        });
    }

    /**
     * Move a hosting account to another administrator or reseller, on one
     * of the new owner's packages (or none, where the new owner allows it).
     * A reseller's limits apply as for an account it creates.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function transfer(User $account, User $owner, ?Package $package, User $actor): void
    {
        if (! $account->hasRole(Role::User)) {
            throw new AuthorizationException(__('Only hosting accounts can be moved to another owner.'));
        }

        if (! $owner->hasRole(Role::Admin, Role::Reseller)) {
            throw ValidationException::withMessages(['owner_id' => __('Accounts can only belong to an administrator or a reseller.')]);
        }

        if ($account->owner_id === $owner->id) {
            throw ValidationException::withMessages(['owner_id' => __('The account already belongs to :owner.', ['owner' => $owner->username])]);
        }

        $this->ensureStatus(
            $account,
            [AccountStatus::Pending, AccountStatus::Active, AccountStatus::Suspended, AccountStatus::Failed],
            __('This account is being deleted.'),
        );
        $this->ensurePackageBelongsTo($package, $owner);

        $previousOwner = $account->owner;
        $previousPackage = $account->package;

        DB::transaction(function () use ($account, $owner, $package, $actor, $previousOwner, $previousPackage): void {
            $this->ensureOwnerCanGiveOut($owner, $package, $account);

            $account->forceFill(['owner_id' => $owner->id, 'package_id' => $package?->id])->save();
            $account->setRelation('owner', $owner);
            $account->setRelation('package', $package);

            if ($previousPackage?->id !== $package?->id) {
                $this->quotas->apply($account, $actor);
            }

            $this->domains->reapplyCgiWebsites(User::query()->whereKey($account->id), $actor);

            $this->audit->record('account.transferred', $account, [
                'username' => $account->username,
                'from' => $previousOwner?->username,
                'to' => $owner->username,
                'package' => $package?->name,
            ], $actor);
        });
    }

    /**
     * Block the account from signing in right away and, for a hosting
     * account, lock it on the server.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function suspend(User $account, ?string $reason, ?User $actor): void
    {
        $this->ensureCanBeChanged($account, $actor, 'suspend');
        $this->ensureStatus($account, [AccountStatus::Active], __('Only active accounts can be suspended.'));

        DB::transaction(function () use ($account, $reason, $actor): void {
            $account->forceFill([
                'status' => AccountStatus::Suspended,
                'suspend_reason' => $reason,
            ])->save();

            if (! $account->isPanelOnly()) {
                $this->tasks->dispatch('account.suspend', ['username' => $account->username], $account, $actor);
            }

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
    public function unsuspend(User $account, ?User $actor): void
    {
        $this->ensureStatus($account, [AccountStatus::Suspended], __('Only suspended accounts can be unsuspended.'));

        DB::transaction(function () use ($account, $actor): void {
            $account->forceFill([
                'status' => AccountStatus::Active,
                'suspend_reason' => null,
                'bandwidth_suspended_at' => null,
            ])->save();

            if (! $account->isPanelOnly()) {
                $this->tasks->dispatch('account.unsuspend', ['username' => $account->username], $account, $actor);
            }

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

        if ($account->isPanelOnly()) {
            $this->deletePanelAccount($account, $actor);

            return;
        }

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

        $owner = $account->owner;

        if ($owner !== null) {
            $this->ensurePackageBelongsTo($package, $owner);
        }

        $previous = $account->package;

        DB::transaction(function () use ($account, $package, $previous, $owner, $actor): void {
            if ($owner !== null) {
                $this->ensureOwnerCanGiveOut($owner, $package, $account);
            }

            $account->forceFill(['package_id' => $package?->id])->save();
            $account->setRelation('package', $package);

            $this->quotas->apply($account, $actor);
            $this->domains->reapplyCgiWebsites(User::query()->whereKey($account->id), $actor);

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

        $accounts = User::query()
            ->whereKey($ids)
            ->when($actor->isReseller(), fn ($query) => $query->where('owner_id', $actor->id)->where('role', Role::User))
            ->orderBy('username')
            ->get();

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
    private function ensureCanBeChanged(User $account, ?User $actor, string $action): void
    {
        if ($actor !== null && $account->is($actor)) {
            throw new AuthorizationException("You cannot {$action} your own account.");
        }

        if ($account->isAdmin()) {
            throw new AuthorizationException("You cannot {$action} an administrator account.");
        }
    }

    /**
     * Delete a reseller (administrators are never deleted from the panel).
     * It must own no hosting accounts any more; its own packages go with it.
     *
     * @throws ValidationException
     */
    private function deletePanelAccount(User $account, User $actor): void
    {
        $owned = $account->ownedAccounts()->count();

        if ($owned > 0) {
            throw ValidationException::withMessages([
                'account' => __('This reseller still owns :count account(s). Move them to another owner or delete them first.', ['count' => $owned]),
            ]);
        }

        DB::transaction(function () use ($account, $actor): void {
            $this->audit->record('account.deleted', $account, ['username' => $account->username, 'role' => $account->role->value], $actor);

            Package::query()->where('owner_id', $account->id)->delete();

            $account->delete();
        });
    }

    /**
     * Refuse a package that is not one the owner hands out: a reseller's
     * accounts only go on its own packages, an administrator's on the
     * administrators' packages.
     *
     * @throws ValidationException
     */
    private function ensurePackageBelongsTo(?Package $package, User $owner): void
    {
        if ($package !== null && ! Package::query()->handedOutBy($owner)->whereKey($package->id)->exists()) {
            throw ValidationException::withMessages([
                'package_id' => __('This package does not belong to :owner.', ['owner' => $owner->username]),
            ]);
        }
    }

    /**
     * Refuse when owner is a reseller that may not give an account (a new
     * one, or except) the package. The reseller's row stays locked until
     * the account is saved, so two requests cannot both take the last place.
     *
     * @throws ValidationException
     */
    private function ensureOwnerCanGiveOut(User $owner, ?Package $package, ?User $except = null): void
    {
        if (! $owner->isReseller()) {
            return;
        }

        $locked = User::query()->with('resellerPackage')->lockForUpdate()->findOrFail($owner->id);

        ResellerLimits::for($locked)->ensureCanGiveOut($package, $except);
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
