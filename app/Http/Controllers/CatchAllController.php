<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCatchAllRequest;
use App\Models\MailDomain;
use App\Services\MailForwarderService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class CatchAllController extends Controller
{
    public function __construct(private MailForwarderService $forwarders) {}

    /**
     * Send a domain's mail for addresses it does not have somewhere, or
     * refuse it again.
     */
    public function __invoke(UpdateCatchAllRequest $request, MailDomain $mailDomain): RedirectResponse
    {
        $target = $request->validated('catchall_target');

        $this->forwarders->setCatchAll($mailDomain, is_string($target) ? $target : null, $request->user());

        $name = $mailDomain->domain->name;

        Inertia::flash('toast', ['type' => 'success', 'message' => is_string($target)
            ? __('Mail for other addresses of :domain now goes to :target.', ['domain' => $name, 'target' => $target])
            : __('Mail for other addresses of :domain is refused again.', ['domain' => $name])]);

        return back();
    }
}
