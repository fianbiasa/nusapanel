<?php

namespace App\Models;

use Database\Factories\TicketAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file sent with a ticket message. It lives on the panel's private disk
 * at path; name is what the sender called it.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int $ticket_message_id
 * @property string $name
 * @property string $path
 * @property int $size Bytes.
 * @property string $mime As the server detected it from the content.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TicketAttachment extends Model
{
    /** @use HasFactory<TicketAttachmentFactory> */
    use HasFactory;

    /**
     * Pictures a browser shows inline; everything else is downloaded.
     *
     * @var list<string>
     */
    public const INLINE_IMAGES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function isImage(): bool
    {
        return in_array($this->mime, self::INLINE_IMAGES, true);
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<TicketMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(TicketMessage::class, 'ticket_message_id');
    }
}
