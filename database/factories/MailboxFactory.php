<?php

namespace Database\Factories;

use App\Models\Mailbox;
use App\Models\MailDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mailbox>
 */
class MailboxFactory extends Factory
{
    /**
     * Define the model's default state: an active mailbox with the password
     * "password" and no quota.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mail_domain_id' => MailDomain::factory(),
            'local_part' => fake()->unique()->lexify('user????'),
            'password_hash' => Mailbox::hashPassword('password'),
            'quota_mb' => null,
            'status' => 'active',
        ];
    }
}
