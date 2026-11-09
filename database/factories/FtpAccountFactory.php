<?php

namespace Database\Factories;

use App\Enums\FtpAccountStatus;
use App\Models\Domain;
use App\Models\FtpAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FtpAccount>
 */
class FtpAccountFactory extends Factory
{
    /**
     * Define the model's default state: a login on a domain of the account
     * with the password "password", shut in that domain's public_html.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'domain_id' => fn (array $attributes) => Domain::factory()->for(User::query()->whereKey($attributes['user_id'])->firstOrFail(), 'account'),
            'login' => fn (array $attributes) => fake()->unique()->lexify('ftp????').'@'.Domain::query()->whereKey($attributes['domain_id'])->firstOrFail()->name,
            'password_hash' => FtpAccount::hashPassword('password'),
            'directory' => fn (array $attributes) => 'domains/'.Domain::query()->whereKey($attributes['domain_id'])->firstOrFail()->name.'/public_html',
            'status' => FtpAccountStatus::Active,
        ];
    }

    /**
     * Indicate that the agent has not prepared the folder yet.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => ['status' => FtpAccountStatus::Pending]);
    }
}
