<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePackageRequest;
use App\Http\Requests\Admin\UpdatePackageRequest;
use App\Models\Package;
use App\Services\PackageService;
use App\Support\ResellerLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hosting packages, for administrators (the administrators' packages,
 * under /admin) and for resellers (their own packages, under /reseller).
 */
class PackageController extends Controller
{
    public function __construct(private PackageService $packages) {}

    /**
     * List the packages by name, with the number of accounts on each.
     */
    public function index(Request $request): Response
    {
        $packages = Package::query()
            ->handedOutBy($request->user())
            ->withCount('accounts')
            ->orderBy('name')
            ->get()
            ->map(fn (Package $package): array => $this->packageData($package));

        return Inertia::render('admin/packages/index', [
            'scope' => $this->scope($request),
            'packages' => $packages,
        ]);
    }

    /**
     * Show the form for creating a package.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('admin/packages/create', [
            'scope' => $this->scope($request),
            'resellerLimits' => $this->resellerLimits($request),
        ]);
    }

    /**
     * Create a package.
     */
    public function store(StorePackageRequest $request): RedirectResponse
    {
        $package = $this->packages->create($request->validated(), $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Package :name created.', ['name' => $package->name]),
        ]);

        return to_route($this->scope($request).'.packages.index');
    }

    /**
     * Show the form for changing a package.
     */
    public function edit(Request $request, Package $package): Response
    {
        Gate::authorize('manage', $package);

        $package->loadCount('accounts');

        return Inertia::render('admin/packages/edit', [
            'scope' => $this->scope($request),
            'package' => $this->packageData($package),
            'resellerLimits' => $this->resellerLimits($request),
        ]);
    }

    /**
     * Change a package; accounts on it follow the new limits right away.
     */
    public function update(UpdatePackageRequest $request, Package $package): RedirectResponse
    {
        $this->packages->update($package, $request->validated(), $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Package :name saved.', ['name' => $package->name]),
        ]);

        return to_route($this->scope($request).'.packages.index');
    }

    /**
     * Delete a package that no account uses.
     */
    public function destroy(Request $request, Package $package): RedirectResponse
    {
        Gate::authorize('manage', $package);

        $this->packages->delete($package, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Package :name deleted.', ['name' => $package->name]),
        ]);

        return back();
    }

    /**
     * Which area the request came from: "reseller" for a reseller's own
     * packages, "admin" for the administrators'.
     */
    private function scope(Request $request): string
    {
        return $request->user()->isReseller() ? 'reseller' : 'admin';
    }

    /**
     * For a reseller, the limits of its reseller package, which its own
     * packages may not go over; null for administrators.
     *
     * @return array<string, int|null>|null
     */
    private function resellerLimits(Request $request): ?array
    {
        $user = $request->user();

        if (! $user->isReseller()) {
            return null;
        }

        $limits = ResellerLimits::for($user);

        return [
            ...array_combine(Package::LIMITS, array_map(fn (string $key): ?int => $limits->limit($key), Package::LIMITS)),
            'ssl' => $limits->allowsSsl() ? 1 : 0,
            'cgi' => $limits->allowsCgi() ? 1 : 0,
        ];
    }

    /**
     * @return array{id: int, name: string, description: string|null, limits: array<string, int|null>, ssl: bool, cgi: bool, accounts_count: int}
     */
    private function packageData(Package $package): array
    {
        $limits = [];

        foreach (Package::LIMITS as $key) {
            $limits[$key] = $package->limit($key);
        }

        return [
            'id' => $package->id,
            'name' => $package->name,
            'description' => $package->description,
            'limits' => $limits,
            'ssl' => $package->ssl,
            'cgi' => $package->cgi,
            'accounts_count' => (int) $package->getAttribute('accounts_count'),
        ];
    }
}
