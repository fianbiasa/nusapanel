<?php

namespace App\Policies;

use App\Models\MailFilter;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MailFilterPolicy
{
    /**
     * Accounts may only delete the filters of their own mailboxes. Other
     * accounts' filters are reported as missing.
     */
    public function delete(User $user, MailFilter $filter): Response
    {
        return $filter->mailbox->mailDomain->domain->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
