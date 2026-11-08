<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePackageRequest;
use App\Http\Requests\Admin\UpdatePackageRequest;
use App\Models\Package;
use App\Services\PackageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PackageController extends Controller
{
    public function __construct(private PackageService $packages) {}

    /**
     * List the packages by name, with the number of accounts on each.
     */
    public function index(): Response
    {
        $packages = Package::query()
            ->withCount('accounts')
            ->orderBy('name')
            ->get()
            ->map(fn (Package $package): array => $this->packageData($package));

        return Inertia::render('admin/packages/index', [
            'packages' => $packages,
        ]);
    }

    /**
     * Show the form for creating a package.
     */
    public function create(): Response
    {
        return Inertia::render('admin/packages/create');
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

        return to_route('admin.packages.index');
    }

    /**
     * Show the form for changing a package.
     */
    public function edit(Package $package): Response
    {
        $package->loadCount('accounts');

        return Inertia::render('admin/packages/edit', [
            'package' => $this->packageData($package),
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

        return to_route('admin.packages.index');
    }

    /**
     * Delete a package that no account uses.
     */
    public function destroy(Request $request, Package $package): RedirectResponse
    {
        $this->packages->delete($package, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Package :name deleted.', ['name' => $package->name]),
        ]);

        return back();
    }

    /**
     * @return array{id: int, name: string, description: string|null, limits: array<string, int|null>, ssl: bool, accounts_count: int}
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
            'accounts_count' => (int) $package->getAttribute('accounts_count'),
        ];
    }
}
