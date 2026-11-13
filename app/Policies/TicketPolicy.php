<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TicketPolicy
{
    /**
     * A ticket is for the account that opened it and whoever answers it:
     * its reseller, or the administrators. Others are told it is missing.
     */
    public function view(User $user, Ticket $ticket): Response
    {
        return $ticket->user_id === $user->id || $ticket->isAnsweredBy($user)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function reply(User $user, Ticket $ticket): Response
    {
        return $this->view($user, $ticket);
    }
}
