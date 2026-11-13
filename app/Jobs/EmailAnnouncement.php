<?php

namespace App\Jobs;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails a message to many accounts to one batch of them, each to its own
 * address, so no recipient sees another's.
 */
class EmailAnnouncement implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  list<int>  $userIds
     */
    public function __construct(
        public int $announcementId,
        public array $userIds,
    ) {}

    public function handle(): void
    {
        $announcement = Announcement::query()->with('sender')->find($this->announcementId);

        if ($announcement === null) {
            return;
        }

        $from = $announcement->sender->name;

        User::query()->whereKey($this->userIds)->each(function (User $user) use ($announcement, $from): void {
            try {
                Mail::raw($announcement->body, fn (Message $message) => $message
                    ->to($user->email)
                    ->subject($announcement->subject)
                    ->from((string) config('mail.from.address'), $from));
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
