<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The server's Telegram bot, which sends alerts to the chats accounts set
 * up. The administrator gives its token from @BotFather; it is kept
 * encrypted and never shown again, and Telegram's own errors are passed on
 * without the token, which is part of every Telegram URL.
 */
class TelegramBot
{
    public const SETTING = 'notifications.telegram';

    private const API = 'https://api.telegram.org/bot';

    private const TOKEN_PATTERN = '/^\d{5,16}:[A-Za-z0-9_-]{30,64}$/D';

    public function __construct(private AuditLogger $audit) {}

    /**
     * The bot's username, or null when no bot is set up.
     */
    public function username(): ?string
    {
        $value = $this->setting();

        return is_string($value['username'] ?? null) ? $value['username'] : null;
    }

    /**
     * Use the bot with token, after Telegram confirms it; null removes it.
     *
     * @throws ValidationException
     */
    public function setToken(?string $token, User $actor): ?string
    {
        if ($token === null) {
            Setting::query()->where('key', self::SETTING)->delete();
            $this->audit->record('server.telegram_bot_removed', null, [], $actor);

            return null;
        }

        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw ValidationException::withMessages(['token' => __('This is not a bot token from @BotFather, which looks like 123456789:AA…')]);
        }

        try {
            $bot = $this->call($token, 'getMe');
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['token' => __('Telegram did not accept the token: :reason', ['reason' => $exception->getMessage()])]);
        }

        $username = (string) ($bot['username'] ?? '');

        Setting::query()->updateOrCreate(['key' => self::SETTING], ['value' => [
            'token' => Crypt::encryptString($token),
            'username' => $username,
        ]]);

        $this->audit->record('server.telegram_bot_set', null, ['username' => $username], $actor);

        return $username;
    }

    /**
     * Send text to a chat.
     *
     * @throws RuntimeException With a reason to show, without the token.
     */
    public function send(string $chatId, string $text): void
    {
        $value = $this->setting();

        if (! is_string($value['token'] ?? null)) {
            throw new RuntimeException(__('No Telegram bot is set up on this server.'));
        }

        $this->call(Crypt::decryptString($value['token']), 'sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    private function call(string $token, string $method, array $params = []): array
    {
        try {
            $response = Http::timeout(15)->asJson()->post(self::API.$token.'/'.$method, $params);
        } catch (ConnectionException) {
            throw new RuntimeException(__('Telegram could not be reached.'));
        }

        if ($response->json('ok') !== true) {
            throw new RuntimeException((string) ($response->json('description') ?? 'HTTP '.$response->status()));
        }

        return (array) $response->json('result');
    }

    /**
     * @return array<string, mixed>
     */
    private function setting(): array
    {
        $value = Setting::query()->where('key', self::SETTING)->value('value');

        return is_array($value) ? $value : [];
    }
}
