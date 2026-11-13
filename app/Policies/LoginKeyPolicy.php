<?php

namespace App\Policies;

use App\Models\LoginKey;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class LoginKeyPolicy
{
    /**
     * Accounts may only delete their own keys. Other accounts' keys are
     * reported as missing.
     */
    public function delete(User $user, LoginKey $loginKey): Response
    {
        return $loginKey->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
