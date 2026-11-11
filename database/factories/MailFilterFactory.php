<?php

namespace Database\Factories;

use App\Models\Mailbox;
use App\Models\MailFilter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailFilter>
 */
class MailFilterFactory extends Factory
{
    /**
     * Define the model's default state: mail whose subject contains
     * "invoice" goes to the folder Invoices.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mailbox_id' => Mailbox::factory(),
            'field' => 'subject',
            'operator' => 'contains',
            'value' => 'invoice',
            'action' => 'move',
            'target' => 'Invoices',
        ];
    }
}
