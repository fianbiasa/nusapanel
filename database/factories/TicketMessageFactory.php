<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketMessage>
 */
class TicketMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'author_id' => null,
            'body' => fake()->paragraph(),
        ];
    }
}
