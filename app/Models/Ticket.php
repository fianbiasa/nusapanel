<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A ticket from an account to whoever looks after it: its reseller, or
 * the administrators when reseller_id is null.
 *
 * @property int $id
 * @property int $user_id Who opened it.
 * @property int|null $reseller_id The reseller who answers it; null is the administrators.
 * @property string $subject
 * @property TicketStatus $status
 * @property TicketPriority $priority
 * @property Carbon|null $last_message_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['subject', 'priority'])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * Who opened the ticket.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The reseller who answers it, if not the administrators.
     *
     * @return BelongsTo<User, $this>
     */
    public function reseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }

    /**
     * @return HasMany<TicketMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }

    /**
     * Whether user answers this ticket, rather than having opened it.
     */
    public function isAnsweredBy(User $user): bool
    {
        return $this->reseller_id === null ? $user->isAdmin() : $this->reseller_id === $user->id;
    }
}
