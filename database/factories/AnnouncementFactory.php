<?php

namespace Database\Factories;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    /**
     * Define the model's default state: an administrator's message to every
     * hosting account.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sender_id' => User::factory()->admin(),
            'audience' => ['user'],
            'subject' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'emailed' => false,
            'recipient_count' => 0,
        ];
    }
}
