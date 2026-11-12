<?php

namespace Database\Factories;

use App\Models\BandwidthUsage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BandwidthUsage>
 */
class BandwidthUsageFactory extends Factory
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
            'web_bytes' => 0,
            'mail_bytes' => 0,
            'ftp_bytes' => 0,
        ];
    }
}
