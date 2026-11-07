<?php

namespace App\Policies;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DomainPolicy
{
    /**
     * Accounts may only delete their own domains. Other accounts' domains
     * are reported as missing so their existence is not revealed.
     */
    public function delete(User $user, Domain $domain): Response
    {
        return $domain->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
