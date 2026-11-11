<?php

namespace App\Models;

use Database\Factories\MailSpamSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A mailbox's own spam filter, the first part of its Sieve script. Without
 * one, mail Rspamd marks "X-Spam: Yes" (score 6) goes into Junk.
 *
 * @property int $id
 * @property int $mailbox_id
 * @property int $threshold The spam score from which mail is spam (1-30).
 * @property string $action junk, delete or tag (left in the inbox).
 * @property list<string> $whitelist Senders (addresses or @domains) whose mail is never spam.
 * @property list<string> $blacklist Senders whose mail is always spam.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MailSpamSetting extends Model
{
    /** @use HasFactory<MailSpamSettingFactory> */
    use HasFactory;

    /**
     * What happens to spam.
     *
     * @var list<string>
     */
    public const ACTIONS = ['junk', 'delete', 'tag'];

    public const MIN_THRESHOLD = 1;

    public const MAX_THRESHOLD = 30;

    /**
     * The most senders of each list.
     */
    public const MAX_SENDERS = 200;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'threshold' => 'integer',
            'whitelist' => 'array',
            'blacklist' => 'array',
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
