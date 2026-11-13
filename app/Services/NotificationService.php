<?php

namespace App\Services;

use App\Enums\Role;
use App\Jobs\DeliverAlert;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\NotificationEvents;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alerts by email and Telegram. Each account chooses its channels and
 * which alerts go to them; the same alert (by its key) is sent once a day
 * at most, so a full disk does not fill the inbox.
 */
class NotificationService
{
    public const DEDUPE_HOURS = 24;

    public function __construct(
        private AuditLogger $audit,
        private TelegramBot $telegram,
    ) {}

    /**
     * Set up or change one of the account's channels.
     *
     * @param  list<string>  $events
     *
     * @throws ValidationException
     */
    public function saveChannel(User $account, string $type, string $target, array $events, bool $enabled, User $actor): NotificationChannel
    {
        if ($type === 'telegram' && $this->telegram->username() === null) {
            throw ValidationException::withMessages(['target' => __('No Telegram bot is set up on this server yet.')]);
        }

        return DB::transaction(function () use ($account, $type, $target, $events, $enabled, $actor): NotificationChannel {
            $channel = $account->notificationChannels()->firstOrNew(['type' => $type]);
            $channel->fill(['target' => $target, 'events' => $events, 'enabled' => $enabled]);

            if ($channel->isDirty('target')) {
                $channel->last_error = null;
            }

            $channel->save();

            $this->audit->record('notification.channel_saved', $channel, [
                'type' => $type,
                'events' => $channel->events,
                'enabled' => $enabled,
            ], $actor);

            return $channel;
        });
    }

    public function removeChannel(NotificationChannel $channel, User $actor): void
    {
        $channel->delete();

        $this->audit->record('notification.channel_removed', $channel, ['type' => $channel->type], $actor);
    }

    /**
     * Send a test now and return why it failed, or null.
     */
    public function test(NotificationChannel $channel): ?string
    {
        DeliverAlert::dispatchSync($channel->id, __('Test alert'), __('This channel receives the alerts of the panel. Nothing needs to be done.'));

        return $channel->refresh()->last_error;
    }

    /**
     * An alert about a hosting account, for the account itself, its
     * reseller and the administrators.
     */
    public function aboutAccount(User $account, string $event, string $subject, string $body, string $key, int $hours = self::DEDUPE_HOURS): int
    {
        $recipients = collect([$account]);

        if ($account->owner?->isReseller()) {
            $recipients->push($account->owner);
        }

        return $this->send($recipients->merge($this->admins()), $event, $subject, $body, $key, $hours);
    }

    /**
     * An alert about the server, for the administrators.
     */
    public function toAdmins(string $event, string $subject, string $body, string $key, int $hours = self::DEDUPE_HOURS): int
    {
        return $this->send($this->admins(), $event, $subject, $body, $key, $hours);
    }

    /**
     * Queue the alert for every enabled channel of the recipients that takes
     * this kind of alert, unless it went out within the last hours. Returns
     * how many channels it was queued for.
     *
     * @param  Collection<int, User>  $recipients
     */
    public function send(Collection $recipients, string $event, string $subject, string $body, string $key, int $hours = self::DEDUPE_HOURS): int
    {
        if (! Cache::add('alert:'.$key, true, now()->addHours($hours))) {
            return 0;
        }

        $channels = NotificationChannel::query()
            ->with('user')
            ->whereIn('user_id', $recipients->pluck('id')->unique())
            ->where('enabled', true)
            ->get()
            ->filter(fn (NotificationChannel $channel): bool => in_array($event, $channel->events, true)
                && in_array($event, NotificationEvents::for($channel->user->role), true));

        foreach ($channels as $channel) {
            DeliverAlert::dispatch($channel->id, $subject, $body);
        }

        return $channels->count();
    }

    /**
     * @return Collection<int, User>
     */
    private function admins(): Collection
    {
        return User::query()->where('role', Role::Admin)->get();
    }
}
