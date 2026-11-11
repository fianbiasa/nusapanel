<?php

namespace Database\Factories;

use App\Models\MailDomain;
use App\Models\MailForwarder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailForwarder>
 */
class MailForwarderFactory extends Factory
{
    /**
     * Define the model's default state: a forwarder to one address
     * elsewhere.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mail_domain_id' => MailDomain::factory(),
            'local_part' => fake()->unique()->lexify('fwd????'),
            'destinations' => fake()->unique()->lexify('someone????').'@example.org',
        ];
    }
}
