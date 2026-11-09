<?php

namespace App\Policies;

use App\Models\DatabaseUser;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DatabaseUserPolicy
{
    /**
     * Accounts may only change their own database users. Other accounts'
     * users are reported as missing so their existence is not revealed.
     */
    public function update(User $user, DatabaseUser $databaseUser): Response
    {
        return $databaseUser->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Accounts may only delete their own database users. Other accounts'
     * users are reported as missing so their existence is not revealed.
     */
    public function delete(User $user, DatabaseUser $databaseUser): Response
    {
        return $this->update($user, $databaseUser);
    }
}
