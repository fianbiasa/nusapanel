<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountStatus;
use App\Enums\BulkAccountAction;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkAccountActionRequest;
use App\Http\Requests\Admin\StoreAccountRequest;
use App\Models\AccountUsage;
use App\Models\IpAddress;
use App\Models\Package;
use App\Models\ResellerPackage;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\AccountService;
use App\Services\IpAddressService;
use App\Support\AccountLimits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The account list, for administrators (every account, under /admin) and
 * for resellers (the hosting accounts they own, under /reseller). Accounts
 * a reseller does not own are reported as missing ({@see UserPolicy}).
 */
class AccountController extends Controller
{
    public function __construct(private AccountService $accounts) {}

    /**
     * The orders the list can be sorted in.
     *
     * @var list<string>
     */
    private const SORTS = ['newest', 'oldest', 'username', 'disk'];

    /**
     * List accounts, newest first or in another order, optionally filtered
     * by username, name or email, status, package, role and owner, with
     * each hosting account's disk usage as last measured.
     */
    public function index(Request $request): Response
    {
        $actor = $request->user();
        $isAdmin = $actor->isAdmin();

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(AccountStatus::class)],
            'package' => ['nullable', 'integer', 'min:1'],
            'role' => ['nullable', Rule::enum(Role::class)],
            'owner' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $status = $validated['status'] ?? null;
        $package = isset($validated['package']) ? (int) $validated['package'] : null;
        $role = $isAdmin ? ($validated['role'] ?? null) : null;
        $owner = $isAdmin && isset($validated['owner']) ? (int) $validated['owner'] : null;
        $sort = $validated['sort'] ?? 'newest';

        $accounts = User::query()
            ->with(['owner:id,username,role', 'package:id,name,disk_mb', 'resellerPackage:id,name', 'primaryDomain:id,user_id,name', 'diskUsage', 'ipv4Address:id,address', 'ipv6Address:id,address'])
            ->withCount('ownedAccounts')
            ->when(! $isAdmin, fn (Builder $query) => $query->where('owner_id', $actor->id)->where('role', Role::User))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('username', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($package !== null, fn (Builder $query) => $query->where('package_id', $package))
            ->when($role !== null, fn (Builder $query) => $query->where('role', $role))
            ->when($owner !== null, fn (Builder $query) => $query->where('owner_id', $owner))
            ->tap(fn (Builder $query) => match ($sort) {
                'oldest' => $query->oldest('id'),
                'username' => $query->orderBy('username'),
                'disk' => $query
                    ->orderByDesc(AccountUsage::query()->select('disk_kb')->whereColumn('account_usage.user_id', 'users.id')->limit(1))
                    ->latest('id'),
                default => $query->latest('id'),
            })
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $account): array => [
                'id' => $account->id,
                'username' => $account->username,
                'name' => $account->name,
                'email' => $account->email,
                'role' => $account->role->value,
                'status' => $account->status->value,
                'suspend_reason' => $account->suspend_reason,
                'owner' => $account->owner?->username,
                'owner_id' => $account->owner_id,
                'packages_of' => $this->packageOwnerKey($account->owner),
                'package_id' => $account->package_id,
                'package' => $account->package?->name,
                'reseller_package_id' => $account->reseller_package_id,
                'reseller_package' => $account->resellerPackage?->name,
                'owned_accounts' => $account->isReseller() ? (int) $account->getAttribute('owned_accounts_count') : null,
                'domain' => $account->primaryDomain?->display_name,
                'ipv4_address_id' => $account->ipv4_address_id,
                'ipv6_address_id' => $account->ipv6_address_id,
                'ipv4' => $account->ipv4Address?->address,
                'ipv6' => $account->ipv6Address?->address,
                'disk' => $this->diskUsage($account),
                'created_at' => $account->created_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/accounts/index', [
            'scope' => $isAdmin ? 'admin' : 'reseller',
            'accounts' => $accounts,
            'packages' => $this->packageOptions($actor),
            'packageGroups' => $isAdmin ? $this->packageGroups() : null,
            'resellerPackages' => $isAdmin ? $this->resellerPackageOptions() : null,
            'owners' => $isAdmin ? $this->ownerOptions() : null,
            'ipAddresses' => $this->ipAddressOptions($actor),
            'filters' => [
                'search' => $search,
                'status' => $status ?? '',
                'package' => $package === null ? '' : (string) $package,
                'role' => $role ?? '',
                'owner' => $owner === null ? '' : (string) $owner,
                'sort' => $sort,
            ],
        ]);
    }

    /**
     * Show the form for creating an account: a hosting account, or for an
     * administrator also a reseller or another administrator.
     */
    public function create(Request $request): Response
    {
        $actor = $request->user();

        return Inertia::render('admin/accounts/create', [
            'scope' => $actor->isAdmin() ? 'admin' : 'reseller',
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'packages' => $this->packageOptions($actor),
            'resellerPackages' => $actor->isAdmin() ? $this->resellerPackageOptions() : null,
        ]);
    }

    /**
     * Create an account. A hosting account is set up on the server by the
     * agent in the background; a reseller or administrator is ready at once.
     */
    public function store(StoreAccountRequest $request): RedirectResponse
    {
        $actor = $request->user();
        $role = Role::from($request->string('role', Role::User->value)->toString());

        if ($role === Role::User) {
            /** @var array{username: string, name: string, email: string, password: string, domain: string|null, package_id: int|null} $data */
            $data = $request->safe()->only(['username', 'name', 'email', 'password', 'domain', 'package_id']);

            $account = $this->accounts->create($data, $actor);
            $message = __('Account :username is being created.', ['username' => $account->username]);
        } else {
            /** @var array{username: string, name: string, email: string, password: string} $data */
            $data = $request->safe()->only(['username', 'name', 'email', 'password']);
            $package = $request->filled('reseller_package_id')
                ? ResellerPackage::query()->findOrFail($request->integer('reseller_package_id'))
                : null;

            $account = $this->accounts->createPanelAccount($data, $role, $package, $actor);
            $message = $role === Role::Reseller
                ? __('Reseller :username created.', ['username' => $account->username])
                : __('Administrator :username created.', ['username' => $account->username]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route(($actor->isAdmin() ? 'admin' : 'reseller').'.accounts.index');
    }

    /**
     * Suspend an account, with an optional reason shown to its managers.
     */
    public function suspend(Request $request, User $account): RedirectResponse
    {
        Gate::authorize('manage', $account);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->accounts->suspend($account, $validated['reason'] ?? null, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Account :username suspended.', ['username' => $account->username]),
        ]);

        return back();
    }

    /**
     * Lift an account's suspension.
     */
    public function unsuspend(Request $request, User $account): RedirectResponse
    {
        Gate::authorize('manage', $account);

        $this->accounts->unsuspend($account, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Account :username unsuspended.', ['username' => $account->username]),
        ]);

        return back();
    }

    /**
     * Move a hosting account to another of its owner's packages, or take it
     * off packages where its owner allows that.
     */
    public function updatePackage(Request $request, User $account): RedirectResponse
    {
        Gate::authorize('manage', $account);

        $validated = $request->validate([
            'package_id' => ['present', 'nullable', 'integer', Rule::exists(Package::class, 'id')],
        ]);

        $package = $validated['package_id'] === null ? null : Package::query()->findOrFail($request->integer('package_id'));

        $this->accounts->changePackage($account, $package, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $package === null
                ? __('Account :username no longer has a package.', ['username' => $account->username])
                : __('Account :username is now on the :package package.', ['username' => $account->username, 'package' => $package->name]),
        ]);

        return back();
    }

    /**
     * Give a hosting account its own IPv4 and IPv6 address, or the
     * server's (null).
     */
    public function updateAddresses(Request $request, User $account, IpAddressService $ipAddresses): RedirectResponse
    {
        Gate::authorize('manage', $account);

        $validated = $request->validate([
            'ipv4_address_id' => ['present', 'nullable', 'integer', Rule::exists(IpAddress::class, 'id')->where('version', 4)],
            'ipv6_address_id' => ['present', 'nullable', 'integer', Rule::exists(IpAddress::class, 'id')->where('version', 6)],
        ]);

        $ipv4 = $validated['ipv4_address_id'] === null ? null : IpAddress::query()->findOrFail((int) $validated['ipv4_address_id']);
        $ipv6 = $validated['ipv6_address_id'] === null ? null : IpAddress::query()->findOrFail((int) $validated['ipv6_address_id']);

        $ipAddresses->setAccountAddresses($account, $ipv4, $ipv6, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The websites of :username are being moved to their addresses.', ['username' => $account->username]),
        ]);

        return back();
    }

    /**
     * Put a reseller on another reseller package, or take it off packages.
     */
    public function updateResellerPackage(Request $request, User $account): RedirectResponse
    {
        Gate::authorize('manage', $account);

        $validated = $request->validate([
            'reseller_package_id' => ['present', 'nullable', 'integer', Rule::exists(ResellerPackage::class, 'id')],
        ]);

        $package = $validated['reseller_package_id'] === null
            ? null
            : ResellerPackage::query()->findOrFail($request->integer('reseller_package_id'));

        $this->accounts->changeResellerPackage($account, $package, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $package === null
                ? __('Reseller :username no longer has a reseller package.', ['username' => $account->username])
                : __('Reseller :username is now on the :package reseller package.', ['username' => $account->username, 'package' => $package->name]),
        ]);

        return back();
    }

    /**
     * Move a hosting account to another administrator or reseller, on one
     * of the new owner's packages.
     */
    public function transfer(Request $request, User $account): RedirectResponse
    {
        Gate::authorize('transfer', $account);

        $validated = $request->validate([
            'owner_id' => ['required', 'integer', Rule::exists(User::class, 'id')->whereIn('role', [Role::Admin->value, Role::Reseller->value])],
            'package_id' => ['present', 'nullable', 'integer', Rule::exists(Package::class, 'id')],
        ]);

        $owner = User::query()->findOrFail($request->integer('owner_id'));
        $package = $validated['package_id'] === null ? null : Package::query()->findOrFail($request->integer('package_id'));

        $this->accounts->transfer($account, $owner, $package, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Account :username now belongs to :owner.', ['username' => $account->username, 'owner' => $owner->username]),
        ]);

        return back();
    }

    /**
     * Delete an account: a hosting account from the server, then from the
     * panel; a reseller that owns no accounts right away.
     */
    public function destroy(Request $request, User $account): RedirectResponse
    {
        Gate::authorize('manage', $account);

        $this->accounts->delete($account, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $account->exists
                ? __('Account :username is being deleted.', ['username' => $account->username])
                : __('Account :username deleted.', ['username' => $account->username]),
        ]);

        return back();
    }

    /**
     * Suspend, unsuspend, delete or move to another package several accounts
     * at once. Accounts the action does not fit are skipped and named in the
     * message, with the reason; a reseller's actions only reach its own.
     */
    public function bulk(BulkAccountActionRequest $request): RedirectResponse
    {
        $action = BulkAccountAction::from($request->string('action')->toString());
        $package = $action === BulkAccountAction::Package && $request->filled('package_id')
            ? Package::query()->findOrFail($request->integer('package_id'))
            : null;

        /** @var list<int> $ids */
        $ids = array_map(intval(...), $request->array('ids'));

        ['done' => $done, 'skipped' => $skipped] = $this->accounts->bulk(
            $action,
            $ids,
            $request->filled('reason') ? $request->string('reason')->toString() : null,
            $package,
            $request->user(),
        );

        $replace = ['count' => count($done)];
        $messages = [];

        if ($done !== []) {
            $messages[] = match ($action) {
                BulkAccountAction::Suspend => __('Accounts suspended: :count.', $replace),
                BulkAccountAction::Unsuspend => __('Accounts unsuspended: :count.', $replace),
                BulkAccountAction::Delete => __('Accounts being deleted: :count.', $replace),
                BulkAccountAction::Package => $package === null
                    ? __('Accounts taken off packages: :count.', $replace)
                    : __('Accounts moved to the :package package: :count.', [...$replace, 'package' => $package->name]),
            };
        }

        if ($skipped !== []) {
            $reasons = collect($skipped)->map(fn (string $reason, string $username): string => "{$username} ({$reason})");

            $messages[] = __('Skipped: :accounts', ['accounts' => $reasons->take(5)->implode('; ')])
                .($reasons->count() > 5 ? ' '.__('and :count more.', ['count' => $reasons->count() - 5]) : '');
        }

        Inertia::flash('toast', [
            'type' => match (true) {
                $skipped === [] => 'success',
                $done === [] => 'error',
                default => 'warning',
            },
            'message' => $messages === [] ? __('No accounts were found.') : implode(' ', $messages),
        ]);

        return back();
    }

    /**
     * The account's disk usage and limit in megabytes; usage is null when it
     * was never measured.
     *
     * @return array{used_mb: int|null, limit_mb: int|null}
     */
    private function diskUsage(User $account): array
    {
        $limits = AccountLimits::for($account);

        return ['used_mb' => $limits->usage('disk_mb'), 'limit_mb' => $limits->limit('disk_mb')];
    }

    /**
     * The packages the actor's accounts can be put on, by name.
     *
     * @return list<array{id: int, name: string}>
     */
    private function packageOptions(User $actor): array
    {
        return $this->options(Package::query()->handedOutBy($actor));
    }

    /**
     * For administrators: the packages each owner hands out, keyed "admin"
     * for the administrators' and by the reseller's id for a reseller's, so
     * an account's package can only be changed to one of its owner's.
     *
     * @return array<string, list<array{id: int, name: string}>>
     */
    private function packageGroups(): array
    {
        $groups = ['admin' => []];

        Package::query()
            ->with('owner:id,role')
            ->orderBy('name')
            ->get(['id', 'name', 'owner_id'])
            ->each(function (Package $package) use (&$groups): void {
                $groups[$this->packageOwnerKey($package->owner)][] = ['id' => $package->id, 'name' => $package->name];
            });

        return $groups;
    }

    /**
     * Whose packages an account of owner goes on: "admin", or the reseller's id.
     */
    /**
     * The addresses the actor's accounts may be given, by whose accounts
     * may have them ({@see self::packageOwnerKey()}), with the accounts
     * that have them: a dedicated one only goes to one of those.
     *
     * @return list<array{id: int, address: string, version: int, of: string, dedicated: bool, used_by: list<int>}>
     */
    private function ipAddressOptions(User $actor): array
    {
        $addresses = $actor->isAdmin()
            ? IpAddress::query()->with('owner:id,role')->orderBy('version')->orderBy('address')->get()
            : IpAddress::handedOutBy($actor)->with('owner:id,role')->orderBy('version')->orderBy('address')->get();

        $users = User::query()
            ->where(fn (Builder $query) => $query
                ->whereIn('ipv4_address_id', $addresses->modelKeys())
                ->orWhereIn('ipv6_address_id', $addresses->modelKeys()))
            ->get(['id', 'ipv4_address_id', 'ipv6_address_id']);

        $options = [];

        foreach ($addresses as $ipAddress) {
            $column = $ipAddress->version === 6 ? 'ipv6_address_id' : 'ipv4_address_id';

            $options[] = [
                'id' => $ipAddress->id,
                'address' => $ipAddress->address,
                'version' => $ipAddress->version,
                'of' => $this->packageOwnerKey($ipAddress->owner),
                'dedicated' => $ipAddress->dedicated,
                'used_by' => array_values(array_map(intval(...), $users->where($column, $ipAddress->id)->modelKeys())),
            ];
        }

        return $options;
    }

    private function packageOwnerKey(?User $owner): string
    {
        return $owner !== null && $owner->isReseller() ? (string) $owner->id : 'admin';
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function resellerPackageOptions(): array
    {
        return $this->options(ResellerPackage::query());
    }

    /**
     * The administrators and resellers an account can belong to.
     *
     * @return list<array{id: int, username: string, role: string}>
     */
    private function ownerOptions(): array
    {
        $owners = User::query()
            ->whereIn('role', [Role::Admin, Role::Reseller])
            ->orderBy('username')
            ->get(['id', 'username', 'role'])
            ->map(fn (User $owner): array => ['id' => $owner->id, 'username' => $owner->username, 'role' => $owner->role->value]);

        return array_values($owners->all());
    }

    /**
     * @param  Builder<covariant Package|ResellerPackage>  $query
     * @return list<array{id: int, name: string}>
     */
    private function options(Builder $query): array
    {
        $options = $query
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Package|ResellerPackage $package): array => ['id' => $package->id, 'name' => $package->name]);

        return array_values($options->all());
    }
}
