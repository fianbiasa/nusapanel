<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreResellerPackageRequest;
use App\Http\Requests\Admin\UpdateResellerPackageRequest;
use App\Models\ResellerPackage;
use App\Services\ResellerPackageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The reseller packages administrators put resellers on.
 */
class ResellerPackageController extends Controller
{
    public function __construct(private ResellerPackageService $packages) {}

    /**
     * List the reseller packages by name, with the number of resellers on each.
     */
    public function index(): Response
    {
        $packages = ResellerPackage::query()
            ->withCount('resellers')
            ->orderBy('name')
            ->get()
            ->map(fn (ResellerPackage $package): array => $this->packageData($package));

        return Inertia::render('admin/reseller-packages/index', [
            'packages' => $packages,
        ]);
    }

    /**
     * Show the form for creating a reseller package.
     */
    public function create(): Response
    {
        return Inertia::render('admin/reseller-packages/create');
    }

    public function store(StoreResellerPackageRequest $request): RedirectResponse
    {
        $package = $this->packages->create($request->validated(), $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Reseller package :name created.', ['name' => $package->name]),
        ]);

        return to_route('admin.reseller-packages.index');
    }

    /**
     * Show the form for changing a reseller package.
     */
    public function edit(ResellerPackage $resellerPackage): Response
    {
        $resellerPackage->loadCount('resellers');

        return Inertia::render('admin/reseller-packages/edit', [
            'package' => $this->packageData($resellerPackage),
        ]);
    }

    /**
     * Change a reseller package; resellers on it follow the new limits right away.
     */
    public function update(UpdateResellerPackageRequest $request, ResellerPackage $resellerPackage): RedirectResponse
    {
        $this->packages->update($resellerPackage, $request->validated(), $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Reseller package :name saved.', ['name' => $resellerPackage->name]),
        ]);

        return to_route('admin.reseller-packages.index');
    }

    /**
     * Delete a reseller package that no reseller uses.
     */
    public function destroy(Request $request, ResellerPackage $resellerPackage): RedirectResponse
    {
        $this->packages->delete($resellerPackage, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Reseller package :name deleted.', ['name' => $resellerPackage->name]),
        ]);

        return back();
    }

    /**
     * @return array{id: int, name: string, description: string|null, limits: array<string, int|null>, ssl: bool, oversell: bool, resellers_count: int}
     */
    private function packageData(ResellerPackage $package): array
    {
        $limits = [];

        foreach (ResellerPackage::LIMITS as $key) {
            $limits[$key] = $package->limit($key);
        }

        return [
            'id' => $package->id,
            'name' => $package->name,
            'description' => $package->description,
            'limits' => $limits,
            'ssl' => $package->ssl,
            'oversell' => $package->oversell,
            'resellers_count' => (int) $package->getAttribute('resellers_count'),
        ];
    }
}
