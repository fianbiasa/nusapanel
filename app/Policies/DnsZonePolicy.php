<?php

namespace App\Policies;

use App\Models\DnsZone;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DnsZonePolicy
{
    /**
     * Accounts may only change the records of their own zones. Other
     * accounts' zones are reported as missing so their existence is not
     * revealed.
     */
    public function update(User $user, DnsZone $dnsZone): Response
    {
        return $dnsZone->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
