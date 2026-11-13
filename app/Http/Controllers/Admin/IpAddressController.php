<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\IpAddress;
use App\Models\User;
use App\Rules\ServableIpAddress;
use App\Services\IpAddressService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The server's extra IP addresses: all of them for administrators (under
 * /admin), its own for a reseller (under /reseller).
 */
class IpAddressController extends Controller
{
    public function __construct(private IpAddressService $ipAddresses) {}

    /**
     * List the addresses with the accounts that have them; for
     * administrators also the server's own addresses and the resellers to
     * give addresses to.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $isAdmin = $user->isAdmin();

        $addresses = IpAddress::query()
            ->with('owner:id,username')
            ->when(! $isAdmin, fn ($query) => $query->where('owner_id', $user->id))
            ->orderBy('version')
            ->orderBy('address')
            ->get();

        $accounts = User::query()
            ->where(fn ($query) => $query
                ->whereIn('ipv4_address_id', $addresses->modelKeys())
                ->orWhereIn('ipv6_address_id', $addresses->modelKeys()))
            ->orderBy('username')
            ->get(['id', 'username', 'ipv4_address_id', 'ipv6_address_id']);

        return Inertia::render('admin/ip-addresses/index', [
            'scope' => $isAdmin ? 'admin' : 'reseller',
            'mainAddress' => config('hosting.public_ip'),
            'addresses' => $addresses->map(fn (IpAddress $ipAddress): array => [
                'id' => $ipAddress->id,
                'address' => $ipAddress->address,
                'version' => $ipAddress->version,
                'prefix' => $ipAddress->prefix,
                'owner' => $ipAddress->owner === null || $ipAddress->owner->isAdmin() ? null : ['id' => $ipAddress->owner->id, 'username' => $ipAddress->owner->username],
                'dedicated' => $ipAddress->dedicated,
                'added' => $ipAddress->added,
                'note' => $ipAddress->note,
                'accounts' => $accounts
                    ->filter(fn (User $account): bool => $account->getAttribute($ipAddress->version === 6 ? 'ipv6_address_id' : 'ipv4_address_id') === $ipAddress->id)
                    ->pluck('username')
                    ->values()
                    ->all(),
            ])->all(),
            'serverAddresses' => $isAdmin ? Inertia::defer(fn (): ?array => $this->ipAddresses->serverAddresses()) : null,
            'resellers' => $isAdmin
                ? User::query()->where('role', Role::Reseller)->orderBy('username')->get(['id', 'username'])->map(fn (User $reseller): array => ['id' => $reseller->id, 'username' => $reseller->username])->all()
                : [],
        ]);
    }

    /**
     * Add an address of the administrators.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'address' => ['required', 'string', 'max:45', new ServableIpAddress],
            'prefix' => ['nullable', 'integer', 'min:1', 'max:128'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $address = (string) $validated['address'];
        $maxPrefix = str_contains($address, ':') ? 128 : 32;
        $prefix = (int) ($validated['prefix'] ?? $maxPrefix);

        if ($prefix > $maxPrefix) {
            return back()->withErrors(['prefix' => __('The prefix length of an IPv4 address is at most 32.')]);
        }

        $ipAddress = $this->ipAddresses->add($address, $prefix, $validated['note'] ?? null, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $ipAddress->added
                ? __(':address was added to the server.', ['address' => $ipAddress->address])
                : __(':address is on the server already and can now be given to accounts.', ['address' => $ipAddress->address]),
        ]);

        return back();
    }

    /**
     * Change an address: who it belongs to (administrators only), whether
     * it is dedicated, and its note.
     */
    public function update(Request $request, IpAddress $ipAddress): RedirectResponse
    {
        Gate::authorize('manage', $ipAddress);

        $isAdmin = $request->user()->isAdmin();

        $validated = $request->validate([
            'dedicated' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
            'owner_id' => $isAdmin
                ? ['present', 'nullable', 'integer', Rule::exists(User::class, 'id')->where('role', Role::Reseller->value)]
                : ['prohibited'],
        ]);

        DB::transaction(function () use ($request, $ipAddress, $validated, $isAdmin): void {
            if ($isAdmin) {
                $owner = $validated['owner_id'] === null ? null : User::query()->findOrFail((int) $validated['owner_id']);
                $this->ipAddresses->assign($ipAddress, $owner, $request->user());
            }

            $this->ipAddresses->update($ipAddress, (bool) $validated['dedicated'], $validated['note'] ?? null, $request->user());
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':address saved.', ['address' => $ipAddress->address])]);

        return back();
    }

    /**
     * Remove an address no account has.
     */
    public function destroy(Request $request, IpAddress $ipAddress): RedirectResponse
    {
        $this->ipAddresses->remove($ipAddress, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':address was removed.', ['address' => $ipAddress->address])]);

        return back();
    }
}
