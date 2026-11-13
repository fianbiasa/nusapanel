<?php

namespace App\Models;

use Database\Factories\NotificationChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Where an account gets its alerts: an email address, or a Telegram chat
 * that the server's bot writes to. One of each per account.
 *
 * @property int $id
 * @property int $user_id
 * @property string $type email or telegram.
 * @property string $target The address, or the Telegram chat id.
 * @property list<string> $events
 * @property bool $enabled
 * @property Carbon|null $last_sent_at
 * @property string|null $last_error Why the last alert could not be sent.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['type', 'target', 'events', 'enabled'])]
class NotificationChannel extends Model
{
    /** @use HasFactory<NotificationChannelFactory> */
    use HasFactory;

    public const TYPES = ['email', 'telegram'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'events' => 'array',
            'enabled' => 'boolean',
            'last_sent_at' => 'datetime',
        ];
    }

    /**
     * The account the alerts are for.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
