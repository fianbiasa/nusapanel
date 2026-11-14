<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\NotificationEvents;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tickets: a hosting account writes to its reseller, or to the
 * administrators when an administrator made it; a reseller writes to the
 * administrators. Each side gets an alert when the other writes, through
 * the channels it chose for tickets. A message can carry files, kept on
 * the panel's private disk under tickets/<ticket id>/.
 */
class TicketService
{
    /**
     * Where attachments are kept on the local disk, one folder per ticket.
     */
    public const ATTACHMENT_DIRECTORY = 'tickets';

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
     * @param  list<UploadedFile>  $files
     *
     * @throws ValidationException
     */
    public function open(User $user, string $subject, TicketPriority $priority, string $body, array $files = []): Ticket
    {
        if ($user->isAdmin()) {
            throw ValidationException::withMessages(['subject' => __('Administrators answer tickets; they do not open them.')]);
        }

        $ticket = DB::transaction(function () use ($user, $subject, $priority, $body, $files): Ticket {
            $ticket = new Ticket(['subject' => $subject, 'priority' => $priority]);
            $ticket->forceFill([
                'user_id' => $user->id,
                'reseller_id' => $this->resellerFor($user),
                'status' => TicketStatus::Open,
                'last_message_at' => now(),
            ])->save();

            $this->addMessage($ticket, $user, $body, $files);

            $this->audit->record('ticket.opened', $ticket, ['subject' => $subject], $user);

            return $ticket;
        });

        $this->notifyOtherSide($ticket, $user, __('New ticket from :username: :subject', ['username' => $user->username, 'subject' => $subject]), $body, $files);

        return $ticket;
    }

    /**
     * Write in a ticket. An answer from whoever looks after the account
     * marks it answered; one from its sender opens it again.
     *
     * @param  list<UploadedFile>  $files
     */
    public function reply(Ticket $ticket, User $author, string $body, array $files = []): TicketMessage
    {
        $message = DB::transaction(function () use ($ticket, $author, $body, $files): TicketMessage {
            $message = $this->addMessage($ticket, $author, $body, $files);

            $ticket->forceFill([
                'status' => $ticket->isAnsweredBy($author) ? TicketStatus::Answered : TicketStatus::Open,
                'last_message_at' => now(),
            ])->save();

            return $message;
        });

        $this->notifyOtherSide($ticket, $author, __('Reply to ticket :subject', ['subject' => $ticket->subject]), $body, $files);

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

    /**
     * Remove the files of every ticket the user opened or answers as a
     * reseller, before its account (and with it those tickets) is deleted.
     */
    public function deleteAttachmentsOf(User $user): void
    {
        Ticket::query()
            ->where(fn ($query) => $query->where('user_id', $user->id)->orWhere('reseller_id', $user->id))
            ->pluck('id')
            ->each(fn (int $ticketId): bool => Storage::disk('local')->deleteDirectory(self::ATTACHMENT_DIRECTORY.'/'.$ticketId));
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function addMessage(Ticket $ticket, User $author, string $body, array $files = []): TicketMessage
    {
        $message = new TicketMessage(['body' => $body]);
        $message->forceFill(['ticket_id' => $ticket->id, 'author_id' => $author->id])->save();

        foreach ($files as $file) {
            $path = $file->store(self::ATTACHMENT_DIRECTORY.'/'.$ticket->id, 'local');

            if ($path === false) {
                throw ValidationException::withMessages(['attachments' => __('The file :name could not be saved.', ['name' => $file->getClientOriginalName()])]);
            }

            $attachment = new TicketAttachment;
            $attachment->forceFill([
                'ticket_id' => $ticket->id,
                'ticket_message_id' => $message->id,
                'name' => self::fileName($file),
                'path' => $path,
                'size' => (int) $file->getSize(),
                'mime' => (string) $file->getMimeType(),
            ])->save();
        }

        return $message;
    }

    /**
     * The name a file was sent with, without folders or control characters.
     */
    private static function fileName(UploadedFile $file): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))) ?? '';

        return Str::limit($name === '' ? 'file' : $name, 200, '');
    }

    /**
     * Tell the side of the ticket that did not write.
     *
     * @param  list<UploadedFile>  $files
     */
    private function notifyOtherSide(Ticket $ticket, User $author, string $subject, string $body, array $files = []): void
    {
        $recipients = $ticket->isAnsweredBy($author)
            ? collect([$ticket->user])
            : $this->answerers($ticket);

        $this->notifications->send(
            $recipients,
            NotificationEvents::TICKET,
            $subject,
            Str::limit($body, 1500)
                .($files === [] ? '' : "\n\n".__('Attachments: :names', ['names' => implode(', ', array_map(self::fileName(...), $files))]))
                ."\n\n".route('tickets.show', $ticket),
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
