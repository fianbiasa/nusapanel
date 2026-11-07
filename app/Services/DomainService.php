<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\DomainStatus;
use App\Enums\Role;
use App\Listeners\ApplyDomainTaskResult;
use App\Models\Domain;
use App\Models\User;
use App\Rules\ValidDomain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds and removes the domains (websites) of hosting accounts. The panel
 * record is updated right away and the matching change on the server is
 * queued for the agent; {@see ApplyDomainTaskResult} applies the result.
 */
class DomainService
{
    public function __construct(
        private TaskDispatcher $tasks,
        private AuditLogger $audit,
    ) {}

    /**
     * Add a domain to a hosting account. It is set up on the server right
     * away for active accounts, and once the account itself has been
     * created for accounts that are still pending.
     *
     * @param  string  $name  Lowercase ASCII (punycode) name that passed {@see ValidDomain}.
     *
     * @throws ValidationException
     */
    public function create(User $account, string $name, User $actor, bool $primary = false): Domain
    {
        if (! $account->hasRole(Role::User)) {
            throw ValidationException::withMessages([
                'domain' => __('Domains can only be added to hosting accounts.'),
            ]);
        }

        if (! in_array($account->status, [AccountStatus::Active, AccountStatus::Pending], true)) {
            throw ValidationException::withMessages([
                'domain' => __('Domains cannot be added while the account is suspended, failed or being deleted.'),
            ]);
        }

        try {
            return DB::transaction(function () use ($account, $name, $actor, $primary): Domain {
                $conflict = $this->findConflict($account, $name, lock: true);

                if ($conflict !== null) {
                    throw ValidationException::withMessages(['domain' => $conflict]);
                }

                $domain = $account->domains()->make([
                    'name' => $name,
                    'php_version' => (string) config('hosting.default_php_version'),
                    'is_primary' => $primary,
                ]);

                $domain->forceFill(['status' => DomainStatus::Pending])->save();

                if ($account->status === AccountStatus::Active) {
                    $this->dispatchApply($domain, $account, $actor);
                }

                $this->audit->record('domain.created', $domain, [
                    'domain' => $domain->name,
                    'username' => $account->username,
                ], $actor);

                return $domain;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'domain' => $this->findConflict($account, $name) ?? __('This domain is already in use by another account.'),
            ]);
        }
    }

    /**
     * Mark the domain for deletion and queue its removal from the server.
     * The panel record is deleted once the agent reports success; the
     * website's files are kept.
     *
     * @throws ValidationException
     */
    public function delete(Domain $domain, User $actor): void
    {
        if (! $domain->status->canBeDeleted()) {
            throw ValidationException::withMessages([
                'domain' => __('This domain is still being set up or is already being deleted.'),
            ]);
        }

        $account = $domain->account;

        DB::transaction(function () use ($domain, $account, $actor): void {
            $domain->forceFill(['status' => DomainStatus::Deleting])->save();

            $this->tasks->dispatch('web.remove_domain', [
                'username' => $account->username,
                'domain' => $domain->name,
            ], $account, $actor);

            $this->audit->record('domain.deleted', $domain, [
                'domain' => $domain->name,
                'username' => $account->username,
            ], $actor);
        });
    }

    /**
     * Delete the panel record of a domain the agent has removed, and remove
     * the account's PHP-FPM pool when no other domain uses that version.
     */
    public function completeRemoval(Domain $domain, ?User $actor): void
    {
        $account = $domain->account;

        DB::transaction(function () use ($domain, $account, $actor): void {
            $domain->delete();

            if ($account->domains()->where('php_version', $domain->php_version)->exists()) {
                return;
            }

            $this->tasks->dispatch('php.remove_pool', [
                'username' => $account->username,
                'php_version' => $domain->php_version,
            ], $account, $actor);
        });
    }

    /**
     * Queue the set-up of domains that were added while the account was
     * still being created.
     */
    public function dispatchPendingDomains(User $account, ?User $actor): void
    {
        $account->domains()
            ->where('status', DomainStatus::Pending)
            ->each(fn (Domain $domain) => $this->dispatchApply($domain, $account, $actor));
    }

    /**
     * Fail domains that were waiting for an account that could not be created.
     */
    public function failPendingDomains(User $account): void
    {
        $account->domains()
            ->where('status', DomainStatus::Pending)
            ->update(['status' => DomainStatus::Failed]);
    }

    private function dispatchApply(Domain $domain, User $account, ?User $actor): void
    {
        $this->tasks->dispatch('web.apply_domain', [
            'username' => $account->username,
            'domain' => $domain->name,
            'php_version' => $domain->php_version,
        ], $account, $actor);
    }

    /**
     * Explain why the account may not use the name, if it may not: the name
     * is taken, or another account owns a parent domain or a subdomain of
     * it. An account may add subdomains of its own domains.
     */
    private function findConflict(User $account, string $name, bool $lock = false): ?string
    {
        $parents = $this->parentNames($name);

        $existing = Domain::query()
            ->where(fn (Builder $query) => $query
                ->whereIn('name', [$name, ...$parents])
                ->orWhere('name', 'like', '%.'.$name))
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())
            ->get(['id', 'user_id', 'name']);

        $taken = $existing->firstWhere('name', $name);

        if ($taken !== null) {
            return $taken->user_id === $account->id
                ? __('You have already added this domain.')
                : __('This domain is already in use by another account.');
        }

        $foreign = $existing->first(fn (Domain $domain): bool => $domain->user_id !== $account->id);

        if ($foreign === null) {
            return null;
        }

        return in_array($foreign->name, $parents, true)
            ? __('This domain is a subdomain of a domain that belongs to another account.')
            : __('Another account already uses a subdomain of this domain.');
    }

    /**
     * Every parent domain of the name that is itself a valid domain,
     * e.g. "b.example.com" and "example.com" for "a.b.example.com".
     *
     * @return list<string>
     */
    private function parentNames(string $name): array
    {
        $labels = explode('.', $name);
        $parents = [];

        for ($i = 1; $i < count($labels) - 1; $i++) {
            $parents[] = implode('.', array_slice($labels, $i));
        }

        return $parents;
    }
}
