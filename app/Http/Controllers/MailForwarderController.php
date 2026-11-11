<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMailForwarderRequest;
use App\Http\Requests\UpdateMailForwarderRequest;
use App\Models\MailForwarder;
use App\Services\MailForwarderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class MailForwarderController extends Controller
{
    public function __construct(private MailForwarderService $forwarders) {}

    /**
     * Add a forwarder; a domain's first forwarder sets up its mail.
     */
    public function store(StoreMailForwarderRequest $request): RedirectResponse
    {
        $account = $request->user();

        /** @var list<string> $destinations */
        $destinations = $request->validated('destinations');

        $forwarder = $this->forwarders->create(
            $account,
            $request->integer('domain_id'),
            $request->string('local_part')->toString(),
            $destinations,
            $account,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Forwarder :address is created.', ['address' => $this->forwarders->address($forwarder)])]);

        return to_route('email.index');
    }

    /**
     * Send a forwarder's mail to other addresses.
     */
    public function update(UpdateMailForwarderRequest $request, MailForwarder $mailForwarder): RedirectResponse
    {
        /** @var list<string> $destinations */
        $destinations = $request->validated('destinations');

        $this->forwarders->update($mailForwarder, $destinations, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Forwarder :address is updated.', ['address' => $this->forwarders->address($mailForwarder)])]);

        return back();
    }

    /**
     * Remove a forwarder.
     */
    public function destroy(Request $request, MailForwarder $mailForwarder): RedirectResponse
    {
        Gate::authorize('delete', $mailForwarder);

        $address = $this->forwarders->address($mailForwarder);

        $this->forwarders->delete($mailForwarder, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Forwarder :address is deleted.', ['address' => $address])]);

        return back();
    }
}
