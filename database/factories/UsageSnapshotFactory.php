<?php

namespace Database\Factories;

use App\Models\UsageSnapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageSnapshot>
 */
class UsageSnapshotFactory extends Factory
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
            'date' => now()->toDateString(),
            'disk_kb' => 1024,
            'inodes' => 10,
        ];
    }
}
