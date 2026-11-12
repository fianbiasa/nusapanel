<?php

namespace App\Policies;

use App\Models\DatabaseRemoteHost;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DatabaseRemoteHostPolicy
{
    /**
     * Accounts may only remove the hosts they allowed themselves. Other
     * accounts' hosts are reported as missing.
     */
    public function delete(User $user, DatabaseRemoteHost $remoteHost): Response
    {
        return $remoteHost->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
