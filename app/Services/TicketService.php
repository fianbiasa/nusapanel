<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\NotificationEvents;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tickets: a hosting account writes to its reseller, or to the
 * administrators when an administrator made it; a reseller writes to the
 * administrators. Each side gets an alert when the other writes, through
 * the channels it chose for tickets.
 */
class TicketService
{
    public function __construct(
        private AuditLogger $audit,
        private NotificationService $notifications,
    ) {}

    /**
     * Who answers the tickets of user: its reseller, or the administrators
     * (null).
     */
    public function resellerFor(User $user): ?int
    {
        return $user->hasRole(Role::User) && $user->owner?->isReseller() ? $user->owner_id : null;
    }

    /**
     * Open a ticket with its first message.
     *
     * @throws ValidationException
     */
    public function open(User $user, string $subject, TicketPriority $priority, string $body): Ticket
    {
        if ($user->isAdmin()) {
            throw ValidationException::withMessages(['subject' => __('Administrators answer tickets; they do not open them.')]);
        }

        $ticket = DB::transaction(function () use ($user, $subject, $priority, $body): Ticket {
            $ticket = new Ticket(['subject' => $subject, 'priority' => $priority]);
            $ticket->forceFill([
                'user_id' => $user->id,
                'reseller_id' => $this->resellerFor($user),
                'status' => TicketStatus::Open,
                'last_message_at' => now(),
            ])->save();

            $this->addMessage($ticket, $user, $body);

            $this->audit->record('ticket.opened', $ticket, ['subject' => $subject], $user);

            return $ticket;
        });

        $this->notifyOtherSide($ticket, $user, __('New ticket from :username: :subject', ['username' => $user->username, 'subject' => $subject]), $body);

        return $ticket;
    }

    /**
     * Write in a ticket. An answer from whoever looks after the account
     * marks it answered; one from its sender opens it again.
     */
    public function reply(Ticket $ticket, User $author, string $body): TicketMessage
    {
        $message = DB::transaction(function () use ($ticket, $author, $body): TicketMessage {
            $message = $this->addMessage($ticket, $author, $body);

            $ticket->forceFill([
                'status' => $ticket->isAnsweredBy($author) ? TicketStatus::Answered : TicketStatus::Open,
                'last_message_at' => now(),
            ])->save();

            return $message;
        });

        $this->notifyOtherSide($ticket, $author, __('Reply to ticket :subject', ['subject' => $ticket->subject]), $body);

        return $message;
    }

    public function close(Ticket $ticket, User $actor): void
    {
        $ticket->forceFill(['status' => TicketStatus::Closed])->save();

        $this->audit->record('ticket.closed', $ticket, ['subject' => $ticket->subject], $actor);
    }

    public function reopen(Ticket $ticket, User $actor): void
    {
        $ticket->forceFill(['status' => TicketStatus::Open])->save();

        $this->audit->record('ticket.reopened', $ticket, ['subject' => $ticket->subject], $actor);
    }

    private function addMessage(Ticket $ticket, User $author, string $body): TicketMessage
    {
        $message = new TicketMessage(['body' => $body]);
        $message->forceFill(['ticket_id' => $ticket->id, 'author_id' => $author->id])->save();

        return $message;
    }

    /**
     * Tell the side of the ticket that did not write.
     */
    private function notifyOtherSide(Ticket $ticket, User $author, string $subject, string $body): void
    {
        $recipients = $ticket->isAnsweredBy($author)
            ? collect([$ticket->user])
            : $this->answerers($ticket);

        $this->notifications->send(
            $recipients,
            NotificationEvents::TICKET,
            $subject,
            Str::limit($body, 1500)."\n\n".route('tickets.show', $ticket),
            'ticket:'.$ticket->id.':'.Str::random(12),
        );
    }

    /**
     * @return Collection<int, User>
     */
    private function answerers(Ticket $ticket): Collection
    {
        return $ticket->reseller_id === null
            ? User::query()->where('role', Role::Admin)->get()
            : collect([$ticket->reseller]);
    }
}
