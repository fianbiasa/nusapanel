<?php

namespace App\Jobs;

use App\Models\NotificationChannel;
use App\Services\TelegramBot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one alert through one channel and keeps how it went on the
 * channel, so its page shows when sending fails. A failed alert is not
 * retried: the next one tries again.
 */
class DeliverAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $channelId,
        public string $subject,
        public string $body,
    ) {}

    public function handle(TelegramBot $telegram): void
    {
        $channel = NotificationChannel::query()->find($this->channelId);

        if ($channel === null) {
            return;
        }

        $subject = '[RAYXO '.$this->hostname().'] '.$this->subject;

        try {
            if ($channel->type === 'telegram') {
                $telegram->send($channel->target, $subject."\n\n".$this->body);
            } else {
                Mail::raw($this->body, fn (Message $message) => $message->to($channel->target)->subject($subject));
            }
        } catch (Throwable $exception) {
            $channel->forceFill(['last_error' => Str::limit($exception->getMessage(), 480)])->save();

            return;
        }

        $channel->forceFill(['last_sent_at' => now(), 'last_error' => null])->save();
    }

    private function hostname(): string
    {
        return (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: gethostname());
    }
}
