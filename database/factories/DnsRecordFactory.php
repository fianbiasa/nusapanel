<?php

namespace Database\Factories;

use App\Models\DnsRecord;
use App\Models\DnsZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DnsRecord>
 */
class DnsRecordFactory extends Factory
{
    /**
     * Define the model's default state: an A record of the zone itself.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dns_zone_id' => DnsZone::factory(),
            'name' => fn (array $attributes) => DnsZone::query()->whereKey($attributes['dns_zone_id'])->value('name'),
            'type' => 'A',
            'content' => fake()->ipv4(),
            'ttl' => 3600,
            'priority' => null,
        ];
    }
}
