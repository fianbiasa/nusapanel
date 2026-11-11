<?php

namespace App\Policies;

use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MailboxPolicy
{
    /**
     * Accounts may only open their own mailboxes (in webmail). Other
     * accounts' mailboxes are reported as missing.
     */
    public function view(User $user, Mailbox $mailbox): Response
    {
        return $this->own($user, $mailbox);
    }

    /**
     * Accounts may only change their own mailboxes. Other accounts'
     * mailboxes are reported as missing so their existence is not revealed.
     */
    public function update(User $user, Mailbox $mailbox): Response
    {
        return $this->own($user, $mailbox);
    }

    /**
     * Accounts may only delete their own mailboxes.
     */
    public function delete(User $user, Mailbox $mailbox): Response
    {
        return $this->own($user, $mailbox);
    }

    private function own(User $user, Mailbox $mailbox): Response
    {
        return $mailbox->mailDomain->domain->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
