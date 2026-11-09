<?php

namespace App\Policies;

use App\Models\Database;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DatabasePolicy
{
    /**
     * Accounts may only delete their own databases. Other accounts'
     * databases are reported as missing so their existence is not revealed.
     */
    public function delete(User $user, Database $database): Response
    {
        return $database->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
