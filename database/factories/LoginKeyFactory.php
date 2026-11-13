<?php

namespace Database\Factories;

use App\Models\LoginKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LoginKey>
 */
class LoginKeyFactory extends Factory
{
    /**
     * Define the model's default state: a key for a hosting account that
     * may read its domains. The key itself is in withSecret().
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $secret = 'rxk_'.Str::random(40);

        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'key_hash' => hash('sha256', $secret),
            'key_prefix' => substr($secret, 0, 12),
            'permissions' => ['domains:read'],
            'allowed_ips' => null,
            'expires_at' => null,
        ];
    }

    /**
     * A key whose secret is known to the test.
     */
    public function withSecret(string $secret): static
    {
        return $this->state(fn (): array => [
            'key_hash' => hash('sha256', $secret),
            'key_prefix' => substr($secret, 0, 12),
        ]);
    }
}
