<?php

namespace App\Http\Controllers;

use App\Enums\DomainType;
use App\Enums\SslStatus;
use App\Http\Requests\StoreDomainRequest;
use App\Models\Domain;
use App\Services\DomainService;
use App\Support\AccountLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DomainController extends Controller
{
    public function __construct(private DomainService $domains) {}

    /**
     * List the signed-in account's websites: its domains, primary domain
     * first, each followed by its subdomains and then its pointers. With
     * the server's public IP address to point them to and what the
     * account's package allows.
     */
    public function index(Request $request): Response
    {
        $account = $request->user();

        $websites = $account->domains()->orderBy('name')->get();
        $childrenByParent = $websites->whereNotNull('parent_id')->groupBy('parent_id');

        $domains = $websites
            ->filter(fn (Domain $domain): bool => $domain->parent_id === null)
            ->sortBy([['is_primary', 'desc'], ['name', 'asc']])
            ->flatMap(fn (Domain $domain): array => [
                $domain,
                ...($childrenByParent->get($domain->id)
                    ?->sortBy(fn (Domain $child): array => [$child->type === DomainType::Subdomain ? 0 : 1, $child->name])
                    ->all() ?? []),
            ])
            ->map(fn (Domain $domain): array => [
                'id' => $domain->id,
                'name' => $domain->name,
                'display_name' => $domain->display_name,
                'type' => $domain->type->value,
                'pointer_mode' => $domain->pointer_mode?->value,
                'parent_id' => $domain->parent_id,
                'parent_display_name' => $domain->parent_id === null ? null : $websites->firstWhere('id', $domain->parent_id)?->display_name,
                'children_count' => $childrenByParent->get($domain->id)?->count() ?? 0,
                'php_version' => $domain->php_version,
                'is_primary' => $domain->is_primary,
                'status' => $domain->status->value,
                'ssl_status' => $domain->ssl_status?->value,
                'ssl_expires_at' => $domain->ssl_expires_at?->toIso8601String(),
                'ssl_error' => $domain->ssl_error,
                'force_https' => $domain->force_https,
                'created_at' => $domain->created_at?->toIso8601String(),
            ])
            ->values();

        return Inertia::render('domains/index', [
            'domains' => $domains,
            'limits' => AccountLimits::for($account)->toArray(),
            'public_ip' => config('hosting.public_ip') ?: null,
        ]);
    }

    /**
     * Add a domain; the agent sets it up on the server in the background.
     */
    public function store(StoreDomainRequest $request): RedirectResponse
    {
        $account = $request->user();

        $domain = $this->domains->create($account, $request->string('domain')->toString(), $account);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Domain :domain is being set up.', ['domain' => $domain->display_name]),
        ]);

        return to_route('domains.index');
    }

    /**
     * Remove a domain from the server, then from the panel. Its files are kept.
     */
    public function destroy(Request $request, Domain $domain): RedirectResponse
    {
        Gate::authorize('delete', $domain);

        $this->domains->delete($domain, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Domain :domain is being removed.', ['domain' => $domain->display_name]),
        ]);

        return back();
    }

    /**
     * Request a Let's Encrypt certificate, or retry a failed request.
     */
    public function requestSsl(Request $request, Domain $domain): RedirectResponse
    {
        Gate::authorize('update', $domain);

        $this->domains->requestSsl($domain, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('An SSL certificate for :domain is being requested.', ['domain' => $domain->display_name]),
        ]);

        return back();
    }

    /**
     * Remove the domain's certificate, or forget a request that failed.
     */
    public function removeSsl(Request $request, Domain $domain): RedirectResponse
    {
        Gate::authorize('update', $domain);

        if ($domain->ssl_status === SslStatus::Failed) {
            $this->domains->cancelSsl($domain, $request->user());

            $message = __('The SSL request for :domain was cancelled.', ['domain' => $domain->display_name]);
        } else {
            $this->domains->removeSsl($domain, $request->user());

            $message = __('The SSL certificate of :domain is being removed.', ['domain' => $domain->display_name]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    /**
     * Turn the redirect from HTTP to HTTPS on or off.
     */
    public function updateForceHttps(Request $request, Domain $domain): RedirectResponse
    {
        Gate::authorize('update', $domain);

        $validated = $request->validate([
            'force_https' => ['required', 'boolean'],
        ]);

        $forceHttps = (bool) $validated['force_https'];

        $this->domains->setForceHttps($domain, $forceHttps, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $forceHttps
                ? __(':domain now always opens over HTTPS.', ['domain' => $domain->display_name])
                : __(':domain can be opened over HTTP again.', ['domain' => $domain->display_name]),
        ]);

        return back();
    }
}
