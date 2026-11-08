<?php

namespace App\Http\Controllers;

use App\Enums\DomainStatus;
use App\Http\Requests\StoreUrlRedirectRequest;
use App\Models\Domain;
use App\Models\UrlRedirect;
use App\Services\UrlRedirectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UrlRedirectController extends Controller
{
    public function __construct(private UrlRedirectService $redirects) {}

    /**
     * List the redirects of the signed-in account's websites, by website
     * and path, with the websites that can take a new one.
     */
    public function index(Request $request): Response
    {
        $account = $request->user();

        $domains = $account->domains()
            ->with(['urlRedirects' => fn ($query) => $query->orderBy('path')])
            ->orderBy('name')
            ->get();

        $redirects = $domains->flatMap(fn (Domain $domain) => $domain->urlRedirects->map(
            fn (UrlRedirect $redirect): array => [
                'id' => $redirect->id,
                'domain' => $domain->name,
                'domain_display_name' => $domain->display_name,
                'path' => $redirect->path,
                'destination' => $redirect->destination,
                'status_code' => $redirect->status_code,
                'wildcard' => $redirect->wildcard,
                'created_at' => $redirect->created_at?->toIso8601String(),
            ],
        ))->values();

        // The primary domain first, then other domains, then subdomains
        // and pointers.
        $websites = $domains
            ->filter(fn (Domain $domain): bool => $domain->status === DomainStatus::Active && $domain->canHaveRedirects())
            ->sortBy(fn (Domain $domain): array => [$domain->is_primary ? 0 : 1, $domain->parent_id === null ? 0 : 1, $domain->name])
            ->map(fn (Domain $domain): array => [
                'id' => $domain->id,
                'name' => $domain->name,
                'display_name' => $domain->display_name,
                'ssl' => $domain->hasActiveSsl(),
            ])
            ->values();

        return Inertia::render('redirects/index', [
            'redirects' => $redirects,
            'websites' => $websites,
            'max_per_website' => UrlRedirect::MAX_PER_DOMAIN,
        ]);
    }

    /**
     * Add a redirect; the agent applies it to the website in the background.
     */
    public function store(StoreUrlRedirectRequest $request): RedirectResponse
    {
        $domain = Domain::query()->findOrFail($request->integer('domain_id'));

        $redirect = $this->redirects->create($domain, [
            'path' => $request->string('path')->toString(),
            'destination' => $request->string('destination')->toString(),
            'status_code' => $request->integer('status_code'),
            'wildcard' => $request->boolean('wildcard'),
        ], $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':url now redirects to :destination.', [
                'url' => $domain->display_name.$redirect->path,
                'destination' => $redirect->destination,
            ]),
        ]);

        return to_route('redirects.index');
    }

    /**
     * Remove a redirect of one of the account's websites.
     */
    public function destroy(Request $request, UrlRedirect $urlRedirect): RedirectResponse
    {
        Gate::authorize('update', $urlRedirect->domain);

        $this->redirects->delete($urlRedirect, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The redirect of :url was removed.', [
                'url' => $urlRedirect->domain->display_name.$urlRedirect->path,
            ]),
        ]);

        return back();
    }
}
