<?php

namespace App\Policies;

use App\Models\MailForwarder;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MailForwarderPolicy
{
    /**
     * Accounts may only change their own forwarders. Other accounts'
     * forwarders are reported as missing so their existence is not
     * revealed.
     */
    public function update(User $user, MailForwarder $forwarder): Response
    {
        return $this->own($user, $forwarder);
    }

    /**
     * Accounts may only delete their own forwarders.
     */
    public function delete(User $user, MailForwarder $forwarder): Response
    {
        return $this->own($user, $forwarder);
    }

    private function own(User $user, MailForwarder $forwarder): Response
    {
        return $forwarder->mailDomain->domain->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
