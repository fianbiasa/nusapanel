<?php

namespace App\Services;

use App\Models\ResellerPackage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates, changes and deletes reseller packages. Resellers on a package
 * follow its new limits right away; one that already gives out more than
 * the new limits keeps its accounts, but cannot add more.
 */
class ResellerPackageService
{
    public function __construct(
        private AuditLogger $audit,
        private DomainService $domains,
        private SshService $ssh,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated name, description, limits, ssl and oversell.
     */
    public function create(array $data, User $actor): ResellerPackage
    {
        return DB::transaction(function () use ($data, $actor): ResellerPackage {
            $package = ResellerPackage::query()->create($data);

            $this->audit->record('reseller_package.created', $package, ['name' => $package->name], $actor);

            return $package;
        });
    }

    /**
     * Change a reseller package; the audit log records the old and new
     * value of every field that changed.
     *
     * @param  array<string, mixed>  $data  Validated name, description, limits, ssl and oversell.
     */
    public function update(ResellerPackage $package, array $data, User $actor): void
    {
        $package->fill($data);

        if (! $package->isDirty()) {
            return;
        }

        $changed = array_keys($package->getDirty());

        DB::transaction(function () use ($package, $changed, $actor): void {
            $from = array_intersect_key($package->getOriginal(), array_flip($changed));

            $package->save();

            $this->audit->record('reseller_package.updated', $package, [
                'name' => $package->name,
                'from' => $from,
                'to' => $package->only($changed),
            ], $actor);

            $accounts = User::query()->whereIn('owner_id', User::query()->select('id')->where('reseller_package_id', $package->id));

            if (in_array('cgi', $changed, true)) {
                $this->domains->reapplyCgiWebsites($accounts, $actor);
            }

            if (in_array('ssh', $changed, true)) {
                $this->ssh->reapply($accounts, $actor);
            }
        });
    }

    /**
     * Delete a reseller package that no reseller uses.
     *
     * @throws ValidationException When resellers still use the package.
     */
    public function delete(ResellerPackage $package, User $actor): void
    {
        $resellers = $package->resellers()->count();

        if ($resellers > 0) {
            throw ValidationException::withMessages([
                'package' => __('This package is still used by :count reseller(s). Move them to another package first.', ['count' => $resellers]),
            ]);
        }

        DB::transaction(function () use ($package, $actor): void {
            $this->audit->record('reseller_package.deleted', $package, ['name' => $package->name], $actor);

            $package->delete();
        });
    }
}
