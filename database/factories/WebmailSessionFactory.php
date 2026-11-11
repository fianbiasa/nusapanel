<?php

namespace Database\Factories;

use App\Models\Mailbox;
use App\Models\WebmailSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebmailSession>
 */
class WebmailSessionFactory extends Factory
{
    /**
     * Define the model's default state: a session of a new mailbox with a
     * random token, valid for another hour.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mailbox_id' => Mailbox::factory(),
            'token_hash' => WebmailSession::hashToken(bin2hex(random_bytes(32))),
            'expires_at' => now()->addHour(),
        ];
    }

    /**
     * A session whose time is up.
     */
    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    /**
     * A session signed in with token.
     */
    public function withToken(string $token): static
    {
        return $this->state(fn (): array => ['token_hash' => WebmailSession::hashToken($token)]);
    }
}
