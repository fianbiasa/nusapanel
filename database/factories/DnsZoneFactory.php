<?php

namespace Database\Factories;

use App\Models\DnsZone;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DnsZone>
 */
class DnsZoneFactory extends Factory
{
    /**
     * Define the model's default state: a zone of a hosting account that is
     * not tied to one of its domains.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'domain_id' => null,
            'name' => fake()->unique()->domainWord().'.test',
        ];
    }
}
