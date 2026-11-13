<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\DnsRecordRequest;
use App\Http\Resources\DnsRecordResource;
use App\Http\Resources\DnsZoneResource;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Services\DnsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The account's DNS zones and their records.
 */
class DnsController extends Controller
{
    public function __construct(private DnsService $dns) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $zones = $request->user()->dnsZones()
            ->with(['records' => fn ($query) => $query->orderBy('name')->orderBy('type')->orderBy('id')])
            ->orderBy('name')
            ->get();

        return DnsZoneResource::collection($zones);
    }

    public function store(DnsRecordRequest $request, DnsZone $dnsZone): JsonResponse
    {
        $record = $this->dns->addRecord($dnsZone, $request->record(), $request->user());

        return (new DnsRecordResource($record))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, DnsRecord $dnsRecord): Response
    {
        Gate::authorize('update', $dnsRecord->zone);

        $this->dns->deleteRecord($dnsRecord, $request->user());

        return response()->noContent();
    }
}
