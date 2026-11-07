<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\DomainStatus;
use App\Enums\Role;
use App\Enums\SslStatus;
use App\Listeners\ApplyDomainTaskResult;
use App\Listeners\ApplySslTaskResult;
use App\Models\Domain;
use App\Models\User;
use App\Rules\ValidDomain;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds and removes the domains (websites) of hosting accounts and their
 * Let's Encrypt certificates. The panel record is updated right away and
 * the matching change on the server is queued for the agent;
 * {@see ApplyDomainTaskResult} and {@see ApplySslTaskResult} apply the
 * results.
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

        if ($domain->ssl_status?->isBusy()) {
            throw ValidationException::withMessages([
                'domain' => __('Wait until the SSL certificate has been installed or removed.'),
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

    /**
     * Ask the agent for a Let's Encrypt certificate, also to retry a request
     * that failed. The agent first checks that the domain points to this
     * server; the website switches to HTTPS once the certificate is issued.
     *
     * @throws ValidationException
     */
    public function requestSsl(Domain $domain, User $actor): void
    {
        $this->ensureDomainIsActive($domain);

        if ($domain->ssl_status !== null && $domain->ssl_status !== SslStatus::Failed) {
            throw ValidationException::withMessages([
                'domain' => __('This domain already has an SSL certificate, or one is being processed.'),
            ]);
        }

        $account = $domain->account;

        DB::transaction(function () use ($domain, $account, $actor): void {
            $domain->forceFill([
                'ssl_status' => SslStatus::Pending,
                'ssl_error' => null,
            ])->save();

            $this->tasks->dispatch('ssl.issue', [
                'username' => $account->username,
                'domain' => $domain->name,
            ], $account, $actor);

            $this->audit->record('domain.ssl_requested', $domain, [
                'domain' => $domain->name,
                'username' => $account->username,
            ], $actor);
        });
    }

    /**
     * Serve the website over plain HTTP again, then delete its certificate:
     * the agent refuses to delete a certificate the website still uses, so
     * the certificate is only deleted once the website has been applied
     * without it.
     *
     * @throws ValidationException
     */
    public function removeSsl(Domain $domain, User $actor): void
    {
        $this->ensureSslIsActive($domain);

        $account = $domain->account;

        DB::transaction(function () use ($domain, $account, $actor): void {
            $domain->forceFill([
                'ssl_status' => SslStatus::Removing,
                'force_https' => false,
            ])->save();

            $this->dispatchApply($domain, $account, $actor);

            $this->audit->record('domain.ssl_removed', $domain, [
                'domain' => $domain->name,
                'username' => $account->username,
            ], $actor);
        });
    }

    /**
     * Forget a certificate request that failed. Nothing was installed on
     * the server, so the agent has nothing to undo.
     *
     * @throws ValidationException
     */
    public function cancelSsl(Domain $domain, User $actor): void
    {
        if ($domain->ssl_status !== SslStatus::Failed) {
            throw ValidationException::withMessages([
                'domain' => __('Only a failed SSL request can be cancelled.'),
            ]);
        }

        DB::transaction(function () use ($domain, $actor): void {
            $domain->forceFill([
                'ssl_status' => null,
                'ssl_error' => null,
            ])->save();

            $this->audit->record('domain.ssl_cancelled', $domain, [
                'domain' => $domain->name,
                'username' => $domain->account->username,
            ], $actor);
        });
    }

    /**
     * Redirect HTTP visitors to HTTPS, or stop doing so.
     *
     * @throws ValidationException
     */
    public function setForceHttps(Domain $domain, bool $forceHttps, User $actor): void
    {
        $this->ensureSslIsActive($domain);

        if ($domain->force_https === $forceHttps) {
            return;
        }

        $account = $domain->account;

        DB::transaction(function () use ($domain, $forceHttps, $account, $actor): void {
            $domain->forceFill(['force_https' => $forceHttps])->save();

            $this->dispatchApply($domain, $account, $actor);

            $this->audit->record($forceHttps ? 'domain.https_forced' : 'domain.https_unforced', $domain, [
                'domain' => $domain->name,
                'username' => $account->username,
            ], $actor);
        });
    }

    /**
     * Record the certificate the agent issued and switch the website to
     * HTTPS. Nothing is queued for an account that is being deleted.
     *
     * @param  array<string, mixed>  $output  The agent's `ssl.issue` result: `names` and an RFC 3339 `expires_at`.
     */
    public function completeSslIssue(Domain $domain, array $output, ?User $actor): void
    {
        $account = $domain->account;

        DB::transaction(function () use ($domain, $output, $account, $actor): void {
            $domain->forceFill([
                'ssl_status' => SslStatus::Active,
                'ssl_names' => $this->certificateNames($output['names'] ?? null),
                'ssl_expires_at' => $this->certificateExpiry($output['expires_at'] ?? null),
                'ssl_error' => null,
            ])->save();

            if ($account->status !== AccountStatus::Deleting) {
                $this->dispatchApply($domain, $account, $actor);
            }
        });
    }

    /**
     * Queue the deletion of a certificate the website no longer uses.
     * Deleting an account removes its certificates on the server anyway.
     */
    public function deleteCertificate(Domain $domain, ?User $actor): void
    {
        $account = $domain->account;

        if ($account->status === AccountStatus::Deleting) {
            return;
        }

        $this->tasks->dispatch('ssl.delete', [
            'username' => $account->username,
            'domain' => $domain->name,
        ], $account, $actor);
    }

    /**
     * Forget the domain's certificate.
     */
    public function clearSsl(Domain $domain): void
    {
        $domain->forceFill([
            'ssl_status' => null,
            'ssl_names' => null,
            'ssl_expires_at' => null,
            'ssl_error' => null,
            'force_https' => false,
        ])->save();
    }

    /**
     * Queue the configuration of every active website again, e.g. after an
     * upgrade changed the web server templates. Websites of accounts that
     * are being created or deleted are left alone.
     *
     * @return int The number of websites queued.
     */
    public function reapplyAll(?User $actor = null): int
    {
        $queued = 0;

        Domain::query()
            ->with('account')
            ->where('status', DomainStatus::Active)
            ->whereHas('account', fn (Builder $query) => $query->whereIn('status', [
                AccountStatus::Active,
                AccountStatus::Suspended,
            ]))
            ->orderBy('id')
            ->each(function (Domain $domain) use ($actor, &$queued): void {
                $this->dispatchApply($domain, $domain->account, $actor);
                $queued++;
            });

        return $queued;
    }

    /**
     * Queue the (re)configuration of the domain's website. It is served over
     * HTTPS only while its certificate is active.
     */
    private function dispatchApply(Domain $domain, User $account, ?User $actor): void
    {
        $ssl = $domain->hasActiveSsl();

        $this->tasks->dispatch('web.apply_domain', [
            'username' => $account->username,
            'domain' => $domain->name,
            'php_version' => $domain->php_version,
            'ssl' => $ssl,
            'force_https' => $ssl && $domain->force_https,
        ], $account, $actor);
    }

    /**
     * @throws ValidationException
     */
    private function ensureDomainIsActive(Domain $domain): void
    {
        if ($domain->status !== DomainStatus::Active) {
            throw ValidationException::withMessages([
                'domain' => __('SSL can only be managed for active domains.'),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function ensureSslIsActive(Domain $domain): void
    {
        $this->ensureDomainIsActive($domain);

        if (! $domain->hasActiveSsl()) {
            throw ValidationException::withMessages([
                'domain' => __('This domain has no active SSL certificate.'),
            ]);
        }
    }

    /**
     * @return list<string>|null
     */
    private function certificateNames(mixed $names): ?array
    {
        if (! is_array($names)) {
            return null;
        }

        return array_values(array_filter($names, is_string(...)));
    }

    private function certificateExpiry(mixed $expiresAt): ?CarbonImmutable
    {
        if (! is_string($expiresAt)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($expiresAt)->setTimezone((string) config('app.timezone'));
        } catch (InvalidFormatException) {
            return null;
        }
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
