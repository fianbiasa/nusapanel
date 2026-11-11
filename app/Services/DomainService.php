<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Enums\PointerMode;
use App\Enums\Role;
use App\Enums\SslSource;
use App\Enums\SslStatus;
use App\Enums\WwwMode;
use App\Listeners\ApplyDomainTaskResult;
use App\Listeners\ApplySslTaskResult;
use App\Models\Domain;
use App\Models\UrlRedirect;
use App\Models\User;
use App\Rules\DocumentRoot;
use App\Rules\ValidDomain;
use App\Support\AccountLimits;
use App\Support\CustomCertificate;
use App\Support\PhpSettings;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Adds and removes the websites of hosting accounts (domains, their
 * subdomains and pointers) and their Let's Encrypt certificates. The panel
 * record is updated right away and the matching change on the server is
 * queued for the agent; {@see ApplyDomainTaskResult} and
 * {@see ApplySslTaskResult} apply the results.
 */
class DomainService
{
    public function __construct(
        private TaskDispatcher $tasks,
        private AuditLogger $audit,
        private ServerSettingsService $serverSettings,
        private DnsService $dns,
    ) {}

    /**
     * Add a domain to a hosting account, within its package's domain limit.
     * It is set up on the server right away for active accounts, and once
     * the account itself has been created for accounts that are still
     * pending.
     *
     * @param  string  $name  Lowercase ASCII (punycode) name that passed {@see ValidDomain}.
     *
     * @throws ValidationException
     */
    public function create(User $account, string $name, User $actor, bool $primary = false): Domain
    {
        return $this->addWebsite($account, $name, $actor, 'domain', [
            'type' => DomainType::Domain,
            'php_version' => (string) config('hosting.default_php_version'),
            'is_primary' => $primary,
        ]);
    }

    /**
     * Add a subdomain of one of the account's domains, within its package's
     * subdomain limit. It gets a folder of its own,
     * domains/<label>.<domain>/public_html.
     *
     * @param  string  $label  One lowercase DNS label, not "www".
     *
     * @throws ValidationException
     */
    public function createSubdomain(Domain $parent, string $label, User $actor): Domain
    {
        $this->ensureCanHaveChildren($parent);

        return $this->addWebsite($parent->account, $label.'.'.$parent->name, $actor, 'name', [
            'type' => DomainType::Subdomain,
            'php_version' => (string) config('hosting.default_php_version'),
        ], $parent);
    }

    /**
     * Add a domain pointer to one of the account's domains, within its
     * package's pointer limit. It shows that domain's website (alias) or
     * redirects its visitors there; an alias uses the same PHP version.
     *
     * @param  string  $name  Lowercase ASCII (punycode) name that passed {@see ValidDomain}.
     *
     * @throws ValidationException
     */
    public function createPointer(Domain $parent, string $name, PointerMode $mode, User $actor): Domain
    {
        $this->ensureCanHaveChildren($parent);

        return $this->addWebsite($parent->account, $name, $actor, 'name', [
            'type' => DomainType::Pointer,
            'pointer_mode' => $mode,
            'php_version' => $parent->php_version,
        ], $parent);
    }

    /**
     * Save a new website and queue its set-up: right away for active
     * accounts, and once the account itself has been created for accounts
     * that are still pending.
     *
     * @param  string  $errorKey  The form field validation errors belong to.
     * @param  array{type: DomainType, php_version: string, is_primary?: bool, pointer_mode?: PointerMode}  $attributes
     *
     * @throws ValidationException
     */
    private function addWebsite(User $account, string $name, User $actor, string $errorKey, array $attributes, ?Domain $parent = null): Domain
    {
        if (! $account->hasRole(Role::User)) {
            throw ValidationException::withMessages([
                $errorKey => __('Domains can only be added to hosting accounts.'),
            ]);
        }

        if (! in_array($account->status, [AccountStatus::Active, AccountStatus::Pending], true)) {
            throw ValidationException::withMessages([
                $errorKey => __('Domains cannot be added while the account is suspended, failed or being deleted.'),
            ]);
        }

        $type = $attributes['type'];

        try {
            return DB::transaction(function () use ($account, $name, $actor, $errorKey, $attributes, $parent, $type): Domain {
                $this->ensureLimitIsNotReached($account, $actor, $type, $errorKey);

                $conflict = $this->findConflict($account, $name, lock: true);

                if ($conflict !== null) {
                    throw ValidationException::withMessages([$errorKey => $conflict]);
                }

                $domain = $account->domains()->make(['name' => $name, ...$attributes]);
                $domain->parent()->associate($parent);
                $domain->forceFill(['status' => DomainStatus::Pending])->save();

                if ($account->status === AccountStatus::Active) {
                    $this->dispatchApply($domain, $account, $actor);
                }

                $this->dns->addDomain($domain, $actor);

                $this->audit->record($type->value.'.created', $domain, array_filter([
                    'domain' => $domain->name,
                    'parent' => $parent?->name,
                    'mode' => $domain->pointer_mode?->value,
                    'username' => $account->username,
                ]), $actor);

                return $domain;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                $errorKey => $this->findConflict($account, $name) ?? __('This domain is already in use by another account.'),
            ]);
        }
    }

    /**
     * Refuse subdomains and pointers of anything but a domain that stays.
     *
     * @throws ValidationException
     */
    private function ensureCanHaveChildren(Domain $parent): void
    {
        if ($parent->type !== DomainType::Domain) {
            throw ValidationException::withMessages([
                'domain_id' => __('Subdomains and pointers can only be added to a domain.'),
            ]);
        }

        if ($parent->status === DomainStatus::Deleting) {
            throw ValidationException::withMessages([
                'domain_id' => __('This domain is being deleted.'),
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
        $this->ensureCanBeDeleted($domain, $domain);

        $account = $domain->account;

        DB::transaction(function () use ($domain, $account, $actor): void {
            $children = $domain->children()->lockForUpdate()->get();

            foreach ($children as $child) {
                if ($child->status !== DomainStatus::Deleting) {
                    $this->ensureCanBeDeleted($child, $domain);
                }
            }

            $domain->forceFill(['status' => DomainStatus::Deleting])->save();
            $this->recordDeletion($domain, $account, $actor);

            foreach ($children as $child) {
                if ($child->status === DomainStatus::Deleting) {
                    continue;
                }

                $child->forceFill(['status' => DomainStatus::Deleting])->save();
                $this->dispatchRemove($child, $account, $actor);
                $this->recordDeletion($child, $account, $actor);
            }

            // A domain is only removed from the server once its subdomains
            // and pointers are gone; completeRemoval() queues it then.
            if ($children->isEmpty()) {
                $this->dispatchRemove($domain, $account, $actor);
            }
        });
    }

    /**
     * Delete the panel record of a website the agent has removed, and
     * remove the account's PHP-FPM pool when no other website uses that
     * version. The parent of a subdomain or pointer that is being deleted
     * itself is removed from the server once its last child is gone.
     */
    public function completeRemoval(Domain $domain, ?User $actor): void
    {
        $account = $domain->account;

        DB::transaction(function () use ($domain, $account, $actor): void {
            $parent = $domain->parent;

            $this->dns->removeDomain($domain, $actor);
            $domain->delete();

            if ($parent?->status === DomainStatus::Deleting && ! $parent->children()->exists()) {
                $this->dispatchRemove($parent, $account, $actor);
            }

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
     * Mark a website the agent could not remove as failed, so it can be
     * deleted again; so is its parent, which was waiting for it.
     */
    public function failRemoval(Domain $domain): void
    {
        DB::transaction(function () use ($domain): void {
            $domain->forceFill(['status' => DomainStatus::Failed])->save();

            $parent = $domain->parent;

            if ($parent?->status === DomainStatus::Deleting) {
                $parent->forceFill(['status' => DomainStatus::Failed])->save();
            }
        });
    }

    /**
     * Queue the set-up of domains that were added while the account was
     * still being created, and send the account's DNS zones.
     */
    public function dispatchPendingDomains(User $account, ?User $actor): void
    {
        $account->domains()
            ->where('status', DomainStatus::Pending)
            ->orderBy('id')
            ->each(fn (Domain $domain) => $this->dispatchApply($domain, $account, $actor));

        $this->dns->syncAccount($account, $actor);
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

        if (! AccountLimits::for($account)->allowsSsl()) {
            throw ValidationException::withMessages([
                'domain' => __('SSL is not included in your package.'),
            ]);
        }

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
     * Serve a subdomain from another folder of the home, inside domains/
     * ({@see DocumentRoot}); null, or its own folder, goes back to
     * domains/<name>/public_html. The files of the old folder are kept.
     *
     * @throws ValidationException
     */
    public function setDocumentRoot(Domain $domain, ?string $root, User $actor): void
    {
        if ($domain->type !== DomainType::Subdomain) {
            throw ValidationException::withMessages([
                'document_root' => __('Only a subdomain can be served from another folder.'),
            ]);
        }

        if (! in_array($domain->status, [DomainStatus::Active, DomainStatus::Failed], true)) {
            throw ValidationException::withMessages([
                'document_root' => __('This domain is still being set up or is already being deleted.'),
            ]);
        }

        if ($root === "domains/{$domain->name}/public_html") {
            $root = null;
        }

        if ($domain->document_root === $root) {
            return;
        }

        $account = $domain->account;
        $previous = $domain->documentRoot();

        DB::transaction(function () use ($domain, $root, $account, $actor, $previous): void {
            $domain->forceFill(['document_root' => $root, 'status' => DomainStatus::Pending])->save();
            $this->dispatchApply($domain, $account, $actor);

            $domain->children()
                ->where('type', DomainType::Pointer)
                ->where('status', '!=', DomainStatus::Deleting)
                ->orderBy('id')
                ->each(function (Domain $pointer) use ($account, $actor): void {
                    $pointer->forceFill(['status' => DomainStatus::Pending])->save();
                    $this->dispatchApply($pointer, $account, $actor);
                });

            $this->audit->record('domain.root_changed', $domain, [
                'domain' => $domain->name,
                'document_root' => ['from' => $previous, 'to' => $domain->documentRoot()],
            ], $actor);
        });
    }

    /**
     * Turn PHP on or off for a domain or subdomain; its alias pointers
     * follow. Without PHP, its scripts are refused rather than served.
     *
     * @throws ValidationException
     */
    public function setPhpEnabled(Domain $domain, bool $enabled, User $actor): void
    {
        if ($domain->type === DomainType::Pointer) {
            throw ValidationException::withMessages([
                'php_enabled' => __('A pointer runs PHP like its domain.'),
            ]);
        }

        if (! in_array($domain->status, [DomainStatus::Active, DomainStatus::Failed], true)) {
            throw ValidationException::withMessages([
                'php_enabled' => __('This domain is still being set up or is already being deleted.'),
            ]);
        }

        if ($domain->ssl_status?->isBusy()) {
            throw ValidationException::withMessages([
                'php_enabled' => __('Wait until the SSL certificate has been installed or removed.'),
            ]);
        }

        if ($domain->php_enabled === $enabled) {
            return;
        }

        $account = $domain->account;

        DB::transaction(function () use ($domain, $enabled, $account, $actor): void {
            $domain->forceFill(['php_enabled' => $enabled, 'status' => DomainStatus::Pending])->save();
            $this->dispatchApply($domain, $account, $actor);

            $domain->children()
                ->where('type', DomainType::Pointer)
                ->where('status', '!=', DomainStatus::Deleting)
                ->orderBy('id')
                ->each(function (Domain $pointer) use ($account, $actor): void {
                    $pointer->forceFill(['status' => DomainStatus::Pending])->save();
                    $this->dispatchApply($pointer, $account, $actor);
                });

            $this->audit->record($enabled ? 'domain.php_enabled' : 'domain.php_disabled', $domain, [
                'domain' => $domain->name,
            ], $actor);
        });
    }

    /**
     * Choose which of the website's two names, with and without www., its
     * visitors end up on. A redirecting pointer sends everyone to its
     * domain anyway.
     *
     * @throws ValidationException
     */
    public function setWwwMode(Domain $domain, WwwMode $mode, User $actor): void
    {
        if ($domain->pointer_mode === PointerMode::Redirect) {
            throw ValidationException::withMessages([
                'www_mode' => __('A redirecting pointer sends every visitor to its domain already.'),
            ]);
        }

        if (! in_array($domain->status, [DomainStatus::Active, DomainStatus::Failed], true)) {
            throw ValidationException::withMessages([
                'www_mode' => __('This domain is still being set up or is already being deleted.'),
            ]);
        }

        if ($domain->www_mode === $mode) {
            return;
        }

        $account = $domain->account;
        $previous = $domain->www_mode;

        DB::transaction(function () use ($domain, $mode, $account, $actor, $previous): void {
            $domain->forceFill(['www_mode' => $mode])->save();

            $this->dispatchApply($domain, $account, $actor);

            $this->audit->record('domain.www_changed', $domain, [
                'domain' => $domain->name,
                'www_mode' => ['from' => $previous->value, 'to' => $mode->value],
            ], $actor);
        });
    }

    /**
     * Run a domain or subdomain on another installed PHP version. Its
     * pointers follow: an alias runs in the same pool as the website it
     * shows. The agent removes the account's pool of the old version once
     * no website uses it anymore.
     *
     * @param  string  $version  An installed version ({@see PhpVersionService::available()}).
     *
     * @throws ValidationException
     */
    public function changePhpVersion(Domain $domain, string $version, User $actor): void
    {
        if ($domain->type === DomainType::Pointer) {
            throw ValidationException::withMessages([
                'php_version' => __('A pointer runs on the PHP version of its domain.'),
            ]);
        }

        if (! in_array($domain->status, [DomainStatus::Active, DomainStatus::Failed], true)) {
            throw ValidationException::withMessages([
                'php_version' => __('This domain is still being set up or is already being deleted.'),
            ]);
        }

        if ($domain->ssl_status?->isBusy()) {
            throw ValidationException::withMessages([
                'php_version' => __('Wait until the SSL certificate has been installed or removed.'),
            ]);
        }

        if ($domain->php_version === $version) {
            return;
        }

        $account = $domain->account;
        $previous = $domain->php_version;

        // Pending until the agent has applied it, so the page shows the
        // switch and refreshes itself; a failed apply marks it failed.
        DB::transaction(function () use ($domain, $version, $account, $actor, $previous): void {
            $domain->forceFill(['php_version' => $version, 'status' => DomainStatus::Pending])->save();
            $this->dispatchApply($domain, $account, $actor);

            $domain->children()
                ->where('type', DomainType::Pointer)
                ->where('status', '!=', DomainStatus::Deleting)
                ->orderBy('id')
                ->each(function (Domain $pointer) use ($version, $account, $actor): void {
                    $pointer->forceFill(['php_version' => $version, 'status' => DomainStatus::Pending])->save();
                    $this->dispatchApply($pointer, $account, $actor);
                });

            $this->audit->record('domain.php_changed', $domain, [
                'domain' => $domain->name,
                'php_version' => ['from' => $previous, 'to' => $version],
                'username' => $account->username,
            ], $actor);
        });
    }

    /**
     * Change the php.ini settings of a domain or subdomain's PHP-FPM pool.
     * Its alias pointers run with the same settings, so they are applied
     * again too.
     *
     * @param  array<string, string>  $settings  The settings that differ from the defaults, in php.ini form ({@see PhpSettings::fromFields()}).
     *
     * @throws ValidationException
     */
    public function updatePhpSettings(Domain $domain, array $settings, User $actor): void
    {
        if ($domain->type === DomainType::Pointer) {
            throw ValidationException::withMessages([
                'memory_limit' => __('A pointer runs with the PHP settings of its domain.'),
            ]);
        }

        if (! in_array($domain->status, [DomainStatus::Active, DomainStatus::Failed], true)) {
            throw ValidationException::withMessages([
                'memory_limit' => __('This domain is still being set up or is already being deleted.'),
            ]);
        }

        if ($domain->ssl_status?->isBusy()) {
            throw ValidationException::withMessages([
                'memory_limit' => __('Wait until the SSL certificate has been installed or removed.'),
            ]);
        }

        $previous = $domain->php_settings ?? [];

        if ($previous == $settings) {
            return;
        }

        $account = $domain->account;

        DB::transaction(function () use ($domain, $settings, $previous, $account, $actor): void {
            $domain->forceFill([
                'php_settings' => $settings === [] ? null : $settings,
                'status' => DomainStatus::Pending,
            ])->save();

            $this->dispatchApply($domain, $account, $actor);

            $domain->children()
                ->where('type', DomainType::Pointer)
                ->where('pointer_mode', PointerMode::Alias)
                ->where('status', '!=', DomainStatus::Deleting)
                ->orderBy('id')
                ->each(function (Domain $pointer) use ($account, $actor): void {
                    $pointer->forceFill(['status' => DomainStatus::Pending])->save();
                    $this->dispatchApply($pointer, $account, $actor);
                });

            $this->audit->record('domain.php_settings_changed', $domain, [
                'domain' => $domain->name,
                'php_settings' => ['from' => $previous, 'to' => $settings],
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
                'ssl_source' => SslSource::LetsEncrypt,
                'ssl_names' => $this->certificateNames($output['names'] ?? null),
                'ssl_expires_at' => $this->certificateExpiry($output['expires_at'] ?? null),
                'ssl_issuer' => "Let's Encrypt",
                'ssl_trusted' => true,
                'ssl_error' => null,
            ])->save();

            if ($account->status !== AccountStatus::Deleting) {
                $this->dispatchApply($domain, $account, $actor);
            }
        });
    }

    /**
     * Install a certificate the customer brought (already checked by
     * {@see CustomCertificate}), in place of no certificate, a failed
     * request, or the website's current certificate, which keeps serving
     * HTTPS until the new one is in place. The agent checks it again;
     * the private key only travels in the task's encrypted payload, which
     * {@see ApplySslTaskResult} empties once the task is done.
     *
     * @throws ValidationException
     */
    public function installCustomCertificate(Domain $domain, string $certificate, string $privateKey, User $actor): void
    {
        $this->ensureDomainIsActive($domain);

        if ($domain->ssl_status?->isBusy()) {
            throw ValidationException::withMessages([
                'certificate' => __('Wait until the SSL certificate has been installed or removed.'),
            ]);
        }

        $account = $domain->account;

        if (! AccountLimits::for($account)->allowsSsl()) {
            throw ValidationException::withMessages([
                'certificate' => __('SSL is not included in your package.'),
            ]);
        }

        DB::transaction(function () use ($domain, $certificate, $privateKey, $account, $actor): void {
            $domain->forceFill([
                'ssl_status' => $domain->hasActiveSsl() ? SslStatus::Replacing : SslStatus::Pending,
                'ssl_error' => null,
            ])->save();

            $this->tasks->dispatch('ssl.install', [
                'username' => $account->username,
                'domain' => $domain->name,
                'certificate' => $certificate,
                'private_key' => $privateKey,
            ], $account, $actor);

            $this->audit->record('domain.ssl_custom_installed', $domain, [
                'domain' => $domain->name,
                'username' => $account->username,
            ], $actor);
        });
    }

    /**
     * Record the custom certificate the agent installed and serve HTTPS
     * with it. Nothing is queued for an account that is being deleted.
     *
     * @param  array<string, mixed>  $output  The agent's `ssl.install` result: `names`, an RFC 3339 `expires_at`, `issuer` and `trusted`.
     */
    public function completeCustomInstall(Domain $domain, array $output, ?User $actor): void
    {
        $account = $domain->account;

        DB::transaction(function () use ($domain, $output, $account, $actor): void {
            $domain->forceFill([
                'ssl_status' => SslStatus::Active,
                'ssl_source' => SslSource::Custom,
                'ssl_names' => $this->certificateNames($output['names'] ?? null),
                'ssl_expires_at' => $this->certificateExpiry($output['expires_at'] ?? null),
                'ssl_issuer' => is_string($output['issuer'] ?? null) ? mb_substr($output['issuer'], 0, 255) : null,
                'ssl_trusted' => ($output['trusted'] ?? false) === true,
                'ssl_error' => null,
            ])->save();

            if ($account->status !== AccountStatus::Deleting) {
                $this->dispatchApply($domain, $account, $actor);
            }
        });
    }

    /**
     * Keep why the agent refused a custom certificate. A website that had
     * a certificate keeps serving HTTPS with it.
     */
    public function failCustomInstall(Domain $domain, ?string $error): void
    {
        $domain->forceFill([
            'ssl_status' => $domain->ssl_status === SslStatus::Replacing ? SslStatus::Active : SslStatus::Failed,
            'ssl_error' => $error,
        ])->save();
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
            'ssl_source' => null,
            'ssl_names' => null,
            'ssl_expires_at' => null,
            'ssl_issuer' => null,
            'ssl_trusted' => null,
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
            ->with(['account', 'parent', 'urlRedirects'])
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
     * Queue the configuration of a website again, e.g. after its URL
     * redirects changed.
     */
    public function reapply(Domain $domain, User $actor): void
    {
        $this->dispatchApply($domain, $domain->account, $actor);
    }

    /**
     * Queue the (re)configuration of the website. It is served over HTTPS
     * only while its certificate is active. A pointer tells the agent what
     * it does and which domain it belongs to; redirects are only sent when
     * there are any, so the payload of a plain website stays as it was.
     */
    private function dispatchApply(Domain $domain, User $account, ?User $actor): void
    {
        $ssl = $domain->hasActiveSsl();

        $params = [
            'username' => $account->username,
            'domain' => $domain->name,
            'php_version' => $domain->php_version,
            'ssl' => $ssl,
            'force_https' => $ssl && $domain->force_https,
        ];

        if ($ssl && $domain->ssl_source === SslSource::Custom) {
            $params['certificate'] = 'custom';
        }

        if ($domain->www_mode !== WwwMode::Both && $domain->pointer_mode !== PointerMode::Redirect) {
            $params['www'] = $domain->www_mode->value;
        }

        // An alias serves the folder of the website it shows.
        $root = match (true) {
            $domain->type === DomainType::Pointer => $domain->parent->document_root ?? null,
            default => $domain->document_root,
        };

        if ($root !== null && $domain->pointer_mode !== PointerMode::Redirect) {
            $params['root'] = $root;
        }

        // An alias runs PHP like the website it shows.
        $runsPhp = $domain->type === DomainType::Pointer ? ($domain->parent->php_enabled ?? true) : $domain->php_enabled;

        if (! $runsPhp && $domain->pointer_mode !== PointerMode::Redirect) {
            $params['no_php'] = true;
        }

        if ($domain->type === DomainType::Pointer) {
            $params['kind'] = ($domain->pointer_mode ?? PointerMode::Alias)->agentKind();
            $params['parent'] = $domain->parent->name ?? throw new LogicException("Pointer {$domain->name} has no parent.");
        }

        // An alias shows (and runs) the website of its domain, with its
        // settings; a redirecting pointer runs no PHP. Upload sizes follow
        // the server's default and limit.
        if ($domain->pointer_mode !== PointerMode::Redirect) {
            $phpSettings = PhpSettings::forAgent(
                $domain->type === DomainType::Pointer ? ($domain->parent->php_settings ?? null) : $domain->php_settings,
                $this->serverSettings->phpDefaults(),
                $this->serverSettings->phpUploadLimit(),
            );

            if ($phpSettings !== []) {
                $params['php_settings'] = $phpSettings;
            }
        }

        if ($domain->canHaveRedirects()) {
            $redirects = $domain->urlRedirects->sortBy('path')->values();

            if ($redirects->isNotEmpty()) {
                $params['redirects'] = $redirects->map(fn (UrlRedirect $redirect): array => $redirect->toAgentParams())->all();
            }
        }

        $this->tasks->dispatch('web.apply_domain', $params, $account, $actor);
    }

    /**
     * Queue the removal of a website from the server.
     */
    private function dispatchRemove(Domain $domain, User $account, ?User $actor): void
    {
        $this->tasks->dispatch('web.remove_domain', [
            'username' => $account->username,
            'domain' => $domain->name,
        ], $account, $actor);
    }

    /**
     * @throws ValidationException
     */
    private function ensureCanBeDeleted(Domain $domain, Domain $deleted): void
    {
        $replace = ['domain' => $domain->display_name];

        if (! $domain->status->canBeDeleted()) {
            throw ValidationException::withMessages([
                'domain' => $domain->is($deleted)
                    ? __('This domain is still being set up or is already being deleted.')
                    : __('Wait until :domain has been set up, then delete the domain again.', $replace),
            ]);
        }

        if ($domain->ssl_status?->isBusy()) {
            throw ValidationException::withMessages([
                'domain' => $domain->is($deleted)
                    ? __('Wait until the SSL certificate has been installed or removed.')
                    : __('Wait until the SSL certificate of :domain has been installed or removed.', $replace),
            ]);
        }
    }

    private function recordDeletion(Domain $domain, User $account, User $actor): void
    {
        $this->audit->record($domain->type->value.'.deleted', $domain, [
            'domain' => $domain->name,
            'username' => $account->username,
        ], $actor);
    }

    /**
     * Refuse a website the account's package has no room for. The
     * account's row stays locked until the website is saved, so two
     * requests cannot both take the last free place.
     *
     * @throws ValidationException
     */
    private function ensureLimitIsNotReached(User $account, User $actor, DomainType $type, string $errorKey): void
    {
        $locked = User::query()->with('package')->lockForUpdate()->findOrFail($account->id);
        $limits = AccountLimits::for($locked);
        $key = $type->limitKey();

        if (! $limits->isReached($key)) {
            return;
        }

        $replace = ['limit' => $limits->limit($key)];
        $own = $actor->is($account);

        throw ValidationException::withMessages([
            $errorKey => match ($type) {
                DomainType::Domain => $own
                    ? __('Your package allows :limit domains. Remove a domain or ask your provider for a bigger package.', $replace)
                    : __("This account's package allows :limit domains.", $replace),
                DomainType::Subdomain => $own
                    ? __('Your package allows :limit subdomains. Remove a subdomain or ask your provider for a bigger package.', $replace)
                    : __("This account's package allows :limit subdomains.", $replace),
                DomainType::Pointer => $own
                    ? __('Your package allows :limit pointers. Remove a pointer or ask your provider for a bigger package.', $replace)
                    : __("This account's package allows :limit pointers.", $replace),
            },
        ]);
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

        if ($domain->ssl_status === SslStatus::Replacing) {
            throw ValidationException::withMessages([
                'domain' => __('Wait until the SSL certificate has been installed or removed.'),
            ]);
        }

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
