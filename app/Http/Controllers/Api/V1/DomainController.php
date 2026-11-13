<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDomainRequest;
use App\Http\Resources\DomainResource;
use App\Models\Domain;
use App\Services\DomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The account's domains, subdomains and pointers. Adding and deleting a
 * domain go on in the background: its status tells when they are done.
 */
class DomainController extends Controller
{
    public function __construct(private DomainService $domains) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return DomainResource::collection($request->user()->domains()->orderBy('name')->get());
    }

    public function store(StoreDomainRequest $request): JsonResponse
    {
        $account = $request->user();

        $domain = $this->domains->create($account, $request->string('domain')->toString(), $account);

        return (new DomainResource($domain))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('delete', $domain);

        $this->domains->delete($domain, $request->user());

        return (new DomainResource($domain->refresh()))->response()->setStatusCode(202);
    }
}
