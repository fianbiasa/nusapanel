<?php

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * Define the model's default state: an open ticket to the
     * administrators.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'reseller_id' => null,
            'subject' => fake()->sentence(4),
            'status' => TicketStatus::Open,
            'priority' => TicketPriority::Normal,
            'last_message_at' => now(),
        ];
    }
}
