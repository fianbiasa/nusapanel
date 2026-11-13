<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\Package;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The hosting accounts an administrator or reseller manages, as in the
 * panel. Creating, deleting and suspending go on in the background: the
 * account's status tells when they are done.
 */
class AccountController extends Controller
{
    public function __construct(private AccountService $accounts) {}

    /**
     * The hosting accounts, by username, 50 a page.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $actor = $request->user();

        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(AccountStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));

        $accounts = User::query()
            ->with(['package:id,name', 'primaryDomain:id,user_id,name'])
            ->where('role', Role::User)
            ->when(! $actor->isAdmin(), fn (Builder $query) => $query->where('owner_id', $actor->id))
            ->when(isset($validated['status']), fn (Builder $query) => $query->where('status', $validated['status']))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('username', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('username')
            ->paginate((int) ($validated['per_page'] ?? 50))
            ->withQueryString();

        return AccountResource::collection($accounts);
    }

    public function show(User $account): AccountResource
    {
        $this->authorizeAccount($account);

        return new AccountResource($account->load(['package', 'primaryDomain']));
    }

    /**
     * Create a hosting account; it is ready once its status is active.
     */
    public function store(StoreAccountRequest $request): JsonResponse
    {
        /** @var array{username: string, name: string, email: string, password: string, domain: string|null, package_id: int|null} $data */
        $data = $request->safe()->only(['username', 'name', 'email', 'password', 'domain', 'package_id']);

        $account = $this->accounts->create($data, $request->user());

        return (new AccountResource($account->load(['package', 'primaryDomain'])))->response()->setStatusCode(201);
    }

    public function suspend(Request $request, User $account): AccountResource
    {
        $this->authorizeAccount($account);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $this->accounts->suspend($account, $validated['reason'] ?? null, $request->user());

        return new AccountResource($account->refresh()->load(['package', 'primaryDomain']));
    }

    public function unsuspend(Request $request, User $account): AccountResource
    {
        $this->authorizeAccount($account);

        $this->accounts->unsuspend($account, $request->user());

        return new AccountResource($account->refresh()->load(['package', 'primaryDomain']));
    }

    /**
     * Put the account on another package, or none (package_id null).
     */
    public function updatePackage(Request $request, User $account): AccountResource
    {
        $this->authorizeAccount($account);

        $validated = $request->validate([
            'package_id' => ['present', 'nullable', 'integer', Rule::exists(Package::class, 'id')],
        ]);

        $package = $validated['package_id'] === null ? null : Package::query()->findOrFail($request->integer('package_id'));

        $this->accounts->changePackage($account, $package, $request->user());

        return new AccountResource($account->refresh()->load(['package', 'primaryDomain']));
    }

    /**
     * Delete the account with everything it has; it is gone once a request
     * for it answers 404.
     */
    public function destroy(Request $request, User $account): JsonResponse
    {
        $this->authorizeAccount($account);

        $this->accounts->delete($account, $request->user());

        return (new AccountResource($account->refresh()->load(['package', 'primaryDomain'])))->response()->setStatusCode(202);
    }

    /**
     * Only hosting accounts the key's account manages; others are missing.
     */
    private function authorizeAccount(User $account): void
    {
        Gate::authorize('manage', $account);

        abort_unless($account->hasRole(Role::User), 404);
    }
}
