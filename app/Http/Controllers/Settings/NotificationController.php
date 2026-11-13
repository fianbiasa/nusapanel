<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\NotificationChannel;
use App\Services\NotificationService;
use App\Services\TelegramBot;
use App\Support\NotificationEvents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where the signed-in account gets its alerts, by email and Telegram.
 */
class NotificationController extends Controller
{
    public function __construct(
        private NotificationService $notifications,
        private TelegramBot $telegram,
    ) {}

    public function index(Request $request): Response
    {
        $account = $request->user();
        $channels = $account->notificationChannels()->get()->keyBy('type');

        return Inertia::render('settings/notifications', [
            'channels' => collect(NotificationChannel::TYPES)->mapWithKeys(fn (string $type): array => [
                $type => $channels->has($type) ? $this->channelData($channels[$type]) : null,
            ]),
            'events' => NotificationEvents::for($account->role),
            'account_email' => $account->email,
            'telegram_bot' => $this->telegram->username(),
        ]);
    }

    /**
     * Set up or change the account's email or Telegram channel.
     */
    public function update(Request $request, string $type): RedirectResponse
    {
        $account = $request->user();

        $validated = $request->validate([
            'target' => $type === 'email'
                ? ['required', 'string', 'email:rfc', 'max:255']
                : ['required', 'string', 'regex:/^-?\d{1,20}$/'],
            'events' => ['present', 'array'],
            'events.*' => ['string', 'distinct', Rule::in(NotificationEvents::for($account->role))],
            'enabled' => ['required', 'boolean'],
        ], [
            'target.regex' => __('A Telegram chat id is a number, like 123456789.'),
        ]);

        $this->notifications->saveChannel($account, $type, $validated['target'], array_values($validated['events']), (bool) $validated['enabled'], $account);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The alert settings are saved.')]);

        return back();
    }

    public function destroy(Request $request, string $type): RedirectResponse
    {
        $channel = $request->user()->notificationChannels()->where('type', $type)->firstOrFail();

        $this->notifications->removeChannel($channel, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The alert settings are saved.')]);

        return back();
    }

    /**
     * Send a test alert now, through the saved channel.
     */
    public function test(Request $request, string $type): RedirectResponse
    {
        $channel = $request->user()->notificationChannels()->where('type', $type)->firstOrFail();

        $error = $this->notifications->test($channel);

        Inertia::flash('toast', $error === null
            ? ['type' => 'success', 'message' => __('The test alert is sent.')]
            : ['type' => 'error', 'message' => __('The test alert could not be sent: :reason', ['reason' => $error])]);

        return back();
    }

    /**
     * @return array{target: string, events: list<string>, enabled: bool, last_sent_at: string|null, last_error: string|null}
     */
    private function channelData(NotificationChannel $channel): array
    {
        return [
            'target' => $channel->target,
            'events' => $channel->events,
            'enabled' => $channel->enabled,
            'last_sent_at' => $channel->last_sent_at?->toIso8601String(),
            'last_error' => $channel->last_error,
        ];
    }
}
