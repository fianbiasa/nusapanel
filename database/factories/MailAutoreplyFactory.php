<?php

namespace Database\Factories;

use App\Models\MailAutoreply;
use App\Models\Mailbox;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailAutoreply>
 */
class MailAutoreplyFactory extends Factory
{
    /**
     * Define the model's default state: an enabled reply without dates.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mailbox_id' => Mailbox::factory(),
            'enabled' => true,
            'subject' => 'Out of office',
            'body' => "Thank you for your mail.\nI will reply when I am back.",
            'starts_on' => null,
            'ends_on' => null,
            'interval_days' => 1,
        ];
    }
}
