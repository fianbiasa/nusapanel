<?php

namespace App\Http\Controllers;

use App\Http\Requests\DnsRecordRequest;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Services\DnsService;
use App\Support\DnsRecordInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DnsController extends Controller
{
    public function __construct(private DnsService $dns) {}

    /**
     * The signed-in account's DNS zones, the records of the chosen one, and
     * the nameservers its domains must use for them to count.
     */
    public function index(Request $request): Response
    {
        $account = $request->user();
        $zones = $account->dnsZones()->orderBy('name')->get();
        $zone = $zones->firstWhere('id', $request->integer('zone')) ?? $zones->first();

        return Inertia::render('dns/index', [
            'zones' => $zones->map(fn (DnsZone $zone): array => ['id' => $zone->id, 'name' => $zone->name])->values(),
            'zone' => $zone === null ? null : ['id' => $zone->id, 'name' => $zone->name],
            'records' => $zone === null ? [] : $zone->records()
                ->orderBy('name')
                ->orderBy('type')
                ->orderBy('id')
                ->get()
                ->map(fn (DnsRecord $record): array => [
                    'id' => $record->id,
                    'name' => DnsRecordInput::relativeName($record->name, $zone->name),
                    'type' => $record->type,
                    'content' => $record->content,
                    'ttl' => $record->ttl,
                    'priority' => $record->priority,
                ])
                ->values(),
            'nameservers' => $this->dns->nameservers(),
            'types' => DnsRecordInput::TYPES,
            'max_records' => DnsRecordInput::MAX_RECORDS,
            'sync' => $this->dns->syncState($account),
        ]);
    }

    /**
     * Add a record to a zone.
     */
    public function store(DnsRecordRequest $request, DnsZone $dnsZone): RedirectResponse
    {
        $record = $this->dns->addRecord($dnsZone, $request->record(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The :type record of :name is being added.', [
            'type' => $record->type,
            'name' => DnsRecordInput::relativeName($record->name, $dnsZone->name),
        ])]);

        return back();
    }

    /**
     * Change a record.
     */
    public function update(DnsRecordRequest $request, DnsRecord $dnsRecord): RedirectResponse
    {
        $this->dns->updateRecord($dnsRecord, $request->record(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The record is being updated.')]);

        return back();
    }

    /**
     * Remove a record.
     */
    public function destroy(Request $request, DnsRecord $dnsRecord): RedirectResponse
    {
        Gate::authorize('update', $dnsRecord->zone);

        $this->dns->deleteRecord($dnsRecord, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The record is being deleted.')]);

        return back();
    }

    /**
     * Put a zone back to the records a new zone starts with.
     */
    public function reset(Request $request, DnsZone $dnsZone): RedirectResponse
    {
        Gate::authorize('update', $dnsZone);

        $this->dns->resetZone($dnsZone, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':zone is being reset to the default records.', ['zone' => $dnsZone->name])]);

        return back();
    }
}
