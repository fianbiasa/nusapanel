<?php

namespace App\Models;

use Database\Factories\WebmailSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A webmail session the panel opened for a mailbox: Roundcube signs in to
 * Dovecot with its token (from this server only) until it expires. Dovecot
 * reads it through the v_mail_webmail_sessions view; the panel keeps only
 * the token's SHA-256.
 *
 * @property int $id
 * @property int $mailbox_id
 * @property string $token_hash SHA-256 of the token, in hex.
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WebmailSession extends Model
{
    /** @use HasFactory<WebmailSessionFactory> */
    use HasFactory;

    use MassPrunable;

    /**
     * The SHA-256 of token, as Dovecot compares it.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Expired sessions, which Dovecot no longer accepts.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
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
