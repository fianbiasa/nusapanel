<?php

namespace Database\Factories;

use App\Models\DnsTemplateRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DnsTemplateRecord>
 */
class DnsTemplateRecordFactory extends Factory
{
    /**
     * Define the model's default state: a TXT record of the zone itself.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => '@',
            'type' => 'TXT',
            'content' => 'verification='.fake()->unique()->lexify('????????'),
            'ttl' => 3600,
            'priority' => null,
        ];
    }
}
