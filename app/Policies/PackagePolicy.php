<?php

namespace App\Policies;

use App\Models\Package;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Administrators manage the administrators' packages; a reseller only its
 * own. Packages of others are reported as missing.
 */
class PackagePolicy
{
    public function manage(User $actor, Package $package): Response
    {
        $allowed = $actor->isReseller()
            ? $package->owner_id === $actor->id
            : $actor->isAdmin() && ($package->owner_id === null || $package->owner?->isAdmin() === true);

        return $allowed ? Response::allow() : Response::denyAsNotFound();
    }
}
