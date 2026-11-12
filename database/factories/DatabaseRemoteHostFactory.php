<?php

namespace Database\Factories;

use App\Models\DatabaseRemoteHost;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatabaseRemoteHost>
 */
class DatabaseRemoteHostFactory extends Factory
{
    /**
     * Define the model's default state: an address of the documentation
     * range.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'host' => '203.0.113.'.fake()->unique()->numberBetween(1, 254),
        ];
    }
}
