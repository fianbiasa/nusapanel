<?php

namespace App\Models;

use Database\Factories\MailFilterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One of a mailbox's filters, part of its Sieve script: when field
 * contains or is value, the mail is moved to the folder in target,
 * forwarded to the address in target (a copy stays), or deleted.
 *
 * @property int $id
 * @property int $mailbox_id
 * @property string $field "from", "to" (To or Cc) or "subject".
 * @property string $operator "contains" or "is".
 * @property string $value
 * @property string $action "move", "forward" or "discard".
 * @property string|null $target The folder or address; null for "discard".
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MailFilter extends Model
{
    /** @use HasFactory<MailFilterFactory> */
    use HasFactory;

    public const FIELDS = ['from', 'to', 'subject'];

    public const OPERATORS = ['contains', 'is'];

    public const ACTIONS = ['move', 'forward', 'discard'];

    /**
     * @return BelongsTo<Mailbox, $this>
     */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }
}
