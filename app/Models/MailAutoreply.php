<?php

namespace App\Models;

use Database\Factories\MailAutoreplyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A mailbox's automatic reply, part of its Sieve script while enabled.
 *
 * @property int $id
 * @property int $mailbox_id
 * @property bool $enabled
 * @property string $subject
 * @property string $body
 * @property Carbon|null $starts_on The first day it replies; null is from now.
 * @property Carbon|null $ends_on The last day it replies; null is until it is turned off.
 * @property int $interval_days How often one sender gets a reply.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MailAutoreply extends Model
{
    /** @use HasFactory<MailAutoreplyFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'interval_days' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Mailbox, $this>
     */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }
}
