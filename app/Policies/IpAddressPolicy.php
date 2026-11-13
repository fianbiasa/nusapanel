<?php

namespace App\Policies;

use App\Models\IpAddress;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Administrators manage every address; a reseller only marks its own
 * dedicated or shared. Addresses of others are reported as missing.
 */
class IpAddressPolicy
{
    public function manage(User $actor, IpAddress $ipAddress): Response
    {
        $allowed = $actor->isAdmin() || ($actor->isReseller() && $ipAddress->owner_id === $actor->id);

        return $allowed ? Response::allow() : Response::denyAsNotFound();
    }
}
