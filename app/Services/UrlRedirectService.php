<?php

namespace App\Services;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\UrlRedirect;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds and removes the URL redirects of websites. The agent writes all
 * redirects of a website into its web server configuration, so every
 * change queues the website's configuration again.
 */
class UrlRedirectService
{
    public function __construct(
        private DomainService $domains,
        private AuditLogger $audit,
    ) {}

    /**
     * Add a redirect to an active website, at most one per path.
     *
     * @param  array{path: string, destination: string, status_code: int, wildcard: bool}  $attributes  Values that passed the StoreUrlRedirectRequest rules.
     *
     * @throws ValidationException
     */
    public function create(Domain $domain, array $attributes, User $actor): UrlRedirect
    {
        if ($domain->status !== DomainStatus::Active) {
            throw ValidationException::withMessages([
                'domain_id' => __('Redirects can only be added to active websites.'),
            ]);
        }

        if (! $domain->canHaveRedirects()) {
            throw ValidationException::withMessages([
                'domain_id' => __('This pointer already redirects every visitor to its domain.'),
            ]);
        }

        try {
            return DB::transaction(function () use ($domain, $attributes, $actor): UrlRedirect {
                $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);

                if ($locked->urlRedirects()->count() >= UrlRedirect::MAX_PER_DOMAIN) {
                    throw ValidationException::withMessages([
                        'path' => __('A website can have at most :limit redirects.', ['limit' => UrlRedirect::MAX_PER_DOMAIN]),
                    ]);
                }

                $redirect = $locked->urlRedirects()->create($attributes);

                $this->domains->reapply($locked, $actor);

                $this->audit->record('redirect.created', $redirect, [
                    'domain' => $locked->name,
                    'path' => $redirect->path,
                    'destination' => $redirect->destination,
                    'status_code' => $redirect->status_code,
                    'wildcard' => $redirect->wildcard,
                    'username' => $locked->account->username,
                ], $actor);

                return $redirect;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'path' => __('This website already has a redirect for this path.'),
            ]);
        }
    }

    /**
     * Remove a redirect. The website is configured again without it unless
     * it is being deleted anyway.
     */
    public function delete(UrlRedirect $redirect, User $actor): void
    {
        $domain = $redirect->domain;

        DB::transaction(function () use ($redirect, $domain, $actor): void {
            $redirect->delete();

            if ($domain->status !== DomainStatus::Deleting) {
                $this->domains->reapply($domain->fresh() ?? $domain, $actor);
            }

            $this->audit->record('redirect.deleted', $redirect, [
                'domain' => $domain->name,
                'path' => $redirect->path,
                'username' => $domain->account->username,
            ], $actor);
        });
    }
}
