<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubdomainRequest;
use App\Models\Domain;
use App\Services\DomainService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class SubdomainController extends Controller
{
    public function __construct(private DomainService $domains) {}

    /**
     * Add a subdomain to one of the signed-in account's domains; the agent
     * sets it up on the server in the background.
     */
    public function store(StoreSubdomainRequest $request): RedirectResponse
    {
        $parent = Domain::query()->findOrFail($request->integer('domain_id'));

        $subdomain = $this->domains->createSubdomain($parent, $request->string('name')->toString(), $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Subdomain :domain is being set up.', ['domain' => $subdomain->display_name]),
        ]);

        return to_route('domains.index');
    }
}
