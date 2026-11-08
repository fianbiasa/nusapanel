<?php

namespace Database\Factories;

use App\Models\AccountUsage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountUsage>
 */
class AccountUsageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'disk_kb' => fake()->numberBetween(1_024, 1_048_576),
            'inodes' => fake()->numberBetween(100, 50_000),
            'measured_at' => now(),
        ];
    }
}
