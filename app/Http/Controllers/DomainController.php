<?php

namespace App\Http\Controllers;

use App\Enums\SslStatus;
use App\Http\Requests\StoreDomainRequest;
use App\Models\Domain;
use App\Services\DomainService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DomainController extends Controller
{
    public function __construct(private DomainService $domains) {}

    /**
     * List the signed-in account's domains, primary domain first, with the
     * server's public IP address to point them to.
     */
    public function index(Request $request): Response
    {
        $domains = $request->user()->domains()
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get()
            ->map(fn (Domain $domain): array => [
                'id' => $domain->id,
                'name' => $domain->name,
                'display_name' => $domain->display_name,
                'php_version' => $domain->php_version,
                'is_primary' => $domain->is_primary,
                'status' => $domain->status->value,
                'ssl_status' => $domain->ssl_status?->value,
                'ssl_expires_at' => $domain->ssl_expires_at?->toIso8601String(),
                'ssl_error' => $domain->ssl_error,
                'force_https' => $domain->force_https,
                'created_at' => $domain->created_at?->toIso8601String(),
            ]);

        return Inertia::render('domains/index', [
            'domains' => $domains,
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
