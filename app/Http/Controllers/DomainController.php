<?php

namespace App\Http\Controllers;

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
     * List the signed-in account's domains, primary domain first.
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
                'created_at' => $domain->created_at?->toIso8601String(),
            ]);

        return Inertia::render('domains/index', [
            'domains' => $domains,
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
}
