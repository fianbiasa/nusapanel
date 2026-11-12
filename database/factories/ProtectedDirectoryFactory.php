<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Models\ProtectedDirectory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProtectedDirectory>
 */
class ProtectedDirectoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'domain_id' => Domain::factory(),
            'path' => '/'.fake()->unique()->slug(2),
            'realm' => 'Restricted area',
        ];
    }
}
