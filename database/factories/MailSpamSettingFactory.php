<?php

namespace Database\Factories;

use App\Models\Mailbox;
use App\Models\MailSpamSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailSpamSetting>
 */
class MailSpamSettingFactory extends Factory
{
    /**
     * Define the model's default state: the server's default filter.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mailbox_id' => Mailbox::factory(),
            'threshold' => 6,
            'action' => 'junk',
            'whitelist' => [],
            'blacklist' => [],
        ];
    }
}
