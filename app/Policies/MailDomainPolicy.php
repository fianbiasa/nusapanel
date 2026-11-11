<?php

namespace App\Policies;

use App\Models\MailDomain;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MailDomainPolicy
{
    /**
     * Accounts may only change the mail settings (the catch-all) of their
     * own domains. Other accounts' domains are reported as missing.
     */
    public function update(User $user, MailDomain $mailDomain): Response
    {
        return $mailDomain->domain->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
