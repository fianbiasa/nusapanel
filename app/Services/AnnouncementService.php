<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Jobs\EmailAnnouncement;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Messages to many accounts: an administrator's to every reseller and/or
 * hosting account, a reseller's to its own hosting accounts. Recipients
 * read them on their dashboard; with email, a copy goes to each account's
 * email address too.
 */
class AnnouncementService
{
    /**
     * How many recipients one email job writes to.
     */
    public const EMAIL_BATCH = 100;

    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  list<string>  $audience  For an administrator: reseller and/or user.
     *
     * @throws ValidationException
     */
    public function send(User $sender, array $audience, string $subject, string $body, bool $email): Announcement
    {
        if ($sender->isReseller()) {
            $audience = [Role::User->value];
        } elseif (! $sender->isAdmin()) {
            throw ValidationException::withMessages(['subject' => __('Only administrators and resellers send messages to many accounts.')]);
        }

        $announcement = DB::transaction(function () use ($sender, $audience, $subject, $body, $email): Announcement {
            $announcement = new Announcement(['audience' => $audience, 'subject' => $subject, 'body' => $body, 'emailed' => $email]);
            $announcement->forceFill([
                'sender_id' => $sender->id,
                'recipient_count' => $this->recipients($sender, $audience)->count(),
            ])->save();

            $this->audit->record('announcement.sent', $announcement, [
                'subject' => $subject,
                'audience' => $announcement->audience,
                'recipients' => $announcement->recipient_count,
                'emailed' => $email,
            ], $sender);

            return $announcement;
        });

        if ($email) {
            $this->recipients($sender, $audience)
                ->select('id')
                ->chunkById(self::EMAIL_BATCH, function ($users) use ($announcement): void {
                    EmailAnnouncement::dispatch($announcement->id, array_values($users->map(fn (User $user): int => $user->id)->all()));
                });
        }

        return $announcement;
    }

    /**
     * The accounts a message reaches: active ones, of the audience's roles
     * (administrator), or the reseller's own hosting accounts.
     *
     * @param  list<string>  $audience
     * @return Builder<User>
     */
    public function recipients(User $sender, array $audience): Builder
    {
        return User::query()
            ->where('status', AccountStatus::Active)
            ->when(
                $sender->isReseller(),
                fn (Builder $query) => $query->where('owner_id', $sender->id)->where('role', Role::User),
                fn (Builder $query) => $query->whereIn('role', $audience),
            );
    }

    /**
     * The messages user received: from the administrators to its role, and
     * a hosting account's from its reseller. Newest first.
     *
     * @return Builder<Announcement>
     */
    public function receivedBy(User $user): Builder
    {
        return Announcement::query()
            ->with('sender:id,username,role')
            ->where(function (Builder $query) use ($user): void {
                $query->where(fn (Builder $query) => $query
                    ->whereHas('sender', fn (Builder $sender) => $sender->where('role', Role::Admin))
                    ->whereJsonContains('audience', $user->role->value));

                if ($user->hasRole(Role::User) && $user->owner?->isReseller()) {
                    $query->orWhere('sender_id', $user->owner_id);
                }
            })
            ->latest('id');
    }
}
