<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DnsTemplateRecordRequest;
use App\Models\DnsRecord;
use App\Models\DnsTemplateRecord;
use App\Models\DnsZone;
use App\Services\DnsService;
use App\Support\DnsRecordInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * DNS administration: every zone on the server, the records of any of them
 * (changed through the same routes customers use, which admins may call
 * for any zone), and the template new zones start with.
 */
class DnsController extends Controller
{
    public function __construct(private DnsService $dns) {}

    /**
     * List the zones, newest first, optionally filtered by zone name or
     * username, with the template.
     */
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $search = strtolower(trim((string) ($validated['search'] ?? '')));

        $zones = DnsZone::query()
            ->with('account:id,username,status')
            ->withCount('records')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhereHas('account', fn (Builder $query) => $query->where('username', 'like', "%{$search}%"))))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (DnsZone $zone): array => [
                'id' => $zone->id,
                'name' => $zone->name,
                'account' => $zone->account->username,
                'account_status' => $zone->account->status->value,
                'records' => $zone->records_count,
            ]);

        return Inertia::render('admin/dns/index', [
            'zones' => $zones,
            'filters' => ['search' => $search],
            'template' => DnsTemplateRecord::ofTemplate(null)
                ->orderBy('id')
                ->get()
                ->map(fn (DnsTemplateRecord $record): array => [
                    'id' => $record->id,
                    'name' => $record->name,
                    'type' => $record->type,
                    'content' => $record->content,
                    'ttl' => $record->ttl,
                    'priority' => $record->priority,
                ])
                ->values(),
            'nameservers' => $this->dns->nameservers(),
            'types' => DnsRecordInput::TYPES,
        ]);
    }

    /**
     * The records of one zone, which the admin may change.
     */
    public function show(DnsZone $dnsZone): Response
    {
        $account = $dnsZone->account;

        return Inertia::render('admin/dns/show', [
            'zone' => [
                'id' => $dnsZone->id,
                'name' => $dnsZone->name,
                'account' => $account->username,
                'account_status' => $account->status->value,
            ],
            'records' => $dnsZone->records()
                ->orderBy('name')
                ->orderBy('type')
                ->orderBy('id')
                ->get()
                ->map(fn (DnsRecord $record): array => [
                    'id' => $record->id,
                    'name' => DnsRecordInput::relativeName($record->name, $dnsZone->name),
                    'type' => $record->type,
                    'content' => $record->content,
                    'ttl' => $record->ttl,
                    'priority' => $record->priority,
                ])
                ->values(),
            'nameservers' => $this->dns->nameserversFor($account),
            'types' => DnsRecordInput::TYPES,
            'max_records' => DnsRecordInput::MAX_RECORDS,
            'sync' => $this->dns->syncState($account),
        ]);
    }

    /**
     * Add a record to the template.
     */
    public function storeTemplate(DnsTemplateRecordRequest $request): RedirectResponse
    {
        $this->dns->addTemplateRecord($request->record(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The template is saved; new zones start with it.')]);

        return back();
    }

    /**
     * Change a record of the template.
     */
    public function updateTemplate(DnsTemplateRecordRequest $request, DnsTemplateRecord $dnsTemplateRecord): RedirectResponse
    {
        Gate::authorize('manage', $dnsTemplateRecord);

        $this->dns->updateTemplateRecord($dnsTemplateRecord, $request->record(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The template is saved; new zones start with it.')]);

        return back();
    }

    /**
     * Remove a record from the template.
     */
    public function destroyTemplate(Request $request, DnsTemplateRecord $dnsTemplateRecord): RedirectResponse
    {
        Gate::authorize('manage', $dnsTemplateRecord);

        $this->dns->deleteTemplateRecord($dnsTemplateRecord, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The template is saved; new zones start with it.')]);

        return back();
    }

    /**
     * Put the template back to the one RAYXO ships.
     */
    public function resetTemplate(Request $request): RedirectResponse
    {
        $this->dns->resetTemplate($request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The template is back to the default.')]);

        return back();
    }
}
