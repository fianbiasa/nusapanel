<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DnsTemplateRecordRequest;
use App\Models\DnsTemplateRecord;
use App\Services\DnsService;
use App\Support\DnsRecordInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A reseller's own DNS template, which the zones of its accounts start
 * with instead of the administrators'.
 */
class ResellerDnsTemplateController extends Controller
{
    public function __construct(private DnsService $dns) {}

    /**
     * Show the template the reseller's accounts get: its own, or the
     * administrators' while it has none.
     */
    public function index(Request $request): Response
    {
        $own = DnsTemplateRecord::ofTemplate($request->user())->exists();

        return Inertia::render('reseller/dns-template', [
            'own' => $own,
            'template' => collect($this->dns->templateOf($request->user()))->map(fn (DnsTemplateRecord $record): array => [
                'id' => $record->id,
                'name' => $record->name,
                'type' => $record->type,
                'content' => $record->content,
                'ttl' => $record->ttl,
                'priority' => $record->priority,
            ])->values(),
            'nameservers' => $this->dns->nameserversFor($request->user()),
            'types' => DnsRecordInput::TYPES,
        ]);
    }

    /**
     * Start the reseller's own template as a copy of the administrators'.
     */
    public function copy(Request $request): RedirectResponse
    {
        $this->dns->copyTemplate($request->user(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your accounts now get your own template; change it as you like.')]);

        return back();
    }

    /**
     * Add a record to the reseller's own template.
     */
    public function store(DnsTemplateRecordRequest $request): RedirectResponse
    {
        $this->dns->addTemplateRecord($request->record(), $request->user(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The template is saved; new zones start with it.')]);

        return back();
    }

    /**
     * Change a record of the reseller's own template.
     */
    public function update(DnsTemplateRecordRequest $request, DnsTemplateRecord $dnsTemplateRecord): RedirectResponse
    {
        Gate::authorize('manage', $dnsTemplateRecord);

        $this->dns->updateTemplateRecord($dnsTemplateRecord, $request->record(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The template is saved; new zones start with it.')]);

        return back();
    }

    /**
     * Remove a record from the reseller's own template.
     */
    public function destroy(Request $request, DnsTemplateRecord $dnsTemplateRecord): RedirectResponse
    {
        Gate::authorize('manage', $dnsTemplateRecord);

        $this->dns->deleteTemplateRecord($dnsTemplateRecord, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The template is saved; new zones start with it.')]);

        return back();
    }

    /**
     * Drop the reseller's own template, so its accounts get the
     * administrators' again.
     */
    public function reset(Request $request): RedirectResponse
    {
        $this->dns->resetTemplate($request->user(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your accounts get the administrators\' template again.')]);

        return back();
    }
}
