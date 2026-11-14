<?php

namespace Database\Factories;

use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketAttachment>
 */
class TicketAttachmentFactory extends Factory
{
    /**
     * Define the model's default state: a small screenshot on a ticket's
     * first message. The file itself is not created.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_message_id' => TicketMessage::factory(),
            'ticket_id' => fn (array $attributes): int => (int) TicketMessage::query()->whereKey($attributes['ticket_message_id'])->value('ticket_id'),
            'name' => 'screenshot.png',
            'path' => fn (array $attributes): string => 'tickets/'.$attributes['ticket_id'].'/'.fake()->uuid().'.png',
            'size' => 20480,
            'mime' => 'image/png',
        ];
    }
}
