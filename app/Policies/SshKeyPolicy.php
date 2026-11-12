<?php

namespace App\Policies;

use App\Models\SshKey;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SshKeyPolicy
{
    /**
     * Accounts may only remove their own keys. Other accounts' keys are
     * reported as missing.
     */
    public function delete(User $user, SshKey $sshKey): Response
    {
        return $sshKey->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
