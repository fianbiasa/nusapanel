<?php

namespace Database\Factories;

use App\Enums\DatabaseStatus;
use App\Models\DatabaseUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatabaseUser>
 */
class DatabaseUserFactory extends Factory
{
    /**
     * Define the model's default state: password "password".
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fn (array $attributes) => User::query()->whereKey($attributes['user_id'])->firstOrFail()->username.'_'.fake()->unique()->lexify('u????'),
            'password_hash' => DatabaseUser::hashPassword('password'),
            'status' => DatabaseStatus::Active,
        ];
    }

    /**
     * Indicate that the agent has not set the user up yet.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => ['status' => DatabaseStatus::Pending]);
    }
}
