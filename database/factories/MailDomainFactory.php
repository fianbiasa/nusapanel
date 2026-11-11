<?php

namespace Database\Factories;

use App\Enums\MailDomainStatus;
use App\Models\Domain;
use App\Models\MailDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailDomain>
 */
class MailDomainFactory extends Factory
{
    /**
     * Define the model's default state: an active mail domain with a DKIM
     * key.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'domain_id' => Domain::factory(),
            'dkim_selector' => 'rayxo',
            'dkim_public_key' => base64_encode(random_bytes(270)),
            'status' => MailDomainStatus::Active,
        ];
    }
}
