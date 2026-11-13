<?php

namespace Database\Factories;

use App\Models\ResourceUsage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResourceUsage>
 */
class ResourceUsageFactory extends Factory
{
    /**
     * Define the model's default state: a quiet day of a hosting account.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => now()->toDateString(),
            'cpu_ms' => 60000,
            'memory_peak_mb' => 128,
        ];
    }
}
