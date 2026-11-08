<?php

namespace App\Services;

use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates, changes and deletes hosting packages. Accounts on a package
 * follow its new limits right away; nothing changes on the server.
 */
class PackageService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Create a package owned by the actor.
     *
     * @param  array<string, mixed>  $data  Validated name, description, limits and ssl.
     */
    public function create(array $data, User $actor): Package
    {
        return DB::transaction(function () use ($data, $actor): Package {
            $package = new Package($data);

            $package->forceFill(['owner_id' => $actor->id])->save();

            $this->audit->record('package.created', $package, ['name' => $package->name], $actor);

            return $package;
        });
    }

    /**
     * Change a package. The audit log records the old and new value of
     * every field that changed.
     *
     * @param  array<string, mixed>  $data  Validated name, description, limits and ssl.
     */
    public function update(Package $package, array $data, User $actor): void
    {
        $package->fill($data);

        if (! $package->isDirty()) {
            return;
        }

        $changed = array_keys($package->getDirty());

        DB::transaction(function () use ($package, $changed, $actor): void {
            $from = array_intersect_key($package->getOriginal(), array_flip($changed));

            $package->save();

            $this->audit->record('package.updated', $package, [
                'name' => $package->name,
                'from' => $from,
                'to' => $package->only($changed),
            ], $actor);
        });
    }

    /**
     * Delete a package that no account uses.
     *
     * @throws ValidationException When accounts still use the package.
     */
    public function delete(Package $package, User $actor): void
    {
        $accounts = $package->accounts()->count();

        if ($accounts > 0) {
            throw ValidationException::withMessages([
                'package' => __('This package is still used by :count account(s). Move them to another package first.', ['count' => $accounts]),
            ]);
        }

        DB::transaction(function () use ($package, $actor): void {
            $this->audit->record('package.deleted', $package, ['name' => $package->name], $actor);

            $package->delete();
        });
    }
}
