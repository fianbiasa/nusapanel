<?php

namespace Database\Factories;

use App\Enums\DatabaseStatus;
use App\Models\Database;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Database>
 */
class DatabaseFactory extends Factory
{
    /**
     * Define the model's default state. The name follows the account's
     * username.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fn (array $attributes) => User::query()->whereKey($attributes['user_id'])->firstOrFail()->username.'_'.fake()->unique()->lexify('db????'),
            'status' => DatabaseStatus::Active,
        ];
    }

    /**
     * Indicate that the agent has not created the database yet.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => ['status' => DatabaseStatus::Pending]);
    }

    /**
     * Indicate that the database is being dropped.
     */
    public function deleting(): static
    {
        return $this->state(fn (array $attributes) => ['status' => DatabaseStatus::Deleting]);
    }
}
