<?php

namespace App\Policies;

use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may manage an account from an account list. Administrators manage
 * every account; a reseller only the hosting accounts it owns. Accounts a
 * reseller may not manage are reported as missing, so their existence is
 * not revealed.
 */
class UserPolicy
{
    public function manage(User $actor, User $account): Response
    {
        return $this->manages($actor, $account)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Signing in as an account: an active one the actor manages, never an
     * administrator or the actor itself.
     */
    public function loginAs(User $actor, User $account): Response
    {
        if (! $this->manages($actor, $account)) {
            return Response::denyAsNotFound();
        }

        return ! $account->is($actor) && ! $account->isAdmin() && $account->status === AccountStatus::Active
            ? Response::allow()
            : Response::deny(__('You can only sign in as an active hosting account or reseller.'));
    }

    /**
     * Moving a hosting account to another owner is for administrators.
     */
    public function transfer(User $actor, User $account): Response
    {
        return $actor->isAdmin() && $account->hasRole(Role::User)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    private function manages(User $actor, User $account): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        return $actor->isReseller()
            && $account->hasRole(Role::User)
            && $account->owner_id === $actor->id;
    }
}
