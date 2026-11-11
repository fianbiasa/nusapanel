<?php

namespace App\Models;

use App\Enums\MailboxStatus;
use App\Support\CryptPassword;
use Database\Factories\MailboxFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * An email account <local_part>@<domain>. Dovecot checks its password and
 * keeps its mail in /home/<account>/imap/<domain>/<local_part>, as the
 * account's Linux user.
 *
 * @property int $id
 * @property int $mail_domain_id
 * @property string $local_part
 * @property string $password_hash SHA512-CRYPT, as Dovecot checks it.
 * @property int|null $quota_mb Null is no limit.
 * @property int|null $daily_send_limit Recipients per 24 hours; null is the account's limit.
 * @property MailboxStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['local_part', 'quota_mb'])]
#[Hidden(['password_hash'])]
class Mailbox extends Model
{
    /** @use HasFactory<MailboxFactory> */
    use HasFactory;

    /**
     * The SHA512-CRYPT hash of password, which Dovecot checks.
     */
    public static function hashPassword(string $password): string
    {
        return CryptPassword::hash($password);
    }

    /**
     * Whether password is the mailbox's password.
     */
    public function passwordMatches(string $password): bool
    {
        return CryptPassword::matches($password, $this->password_hash);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quota_mb' => 'integer',
            'daily_send_limit' => 'integer',
            'status' => MailboxStatus::class,
        ];
    }

    /**
     * @return BelongsTo<MailDomain, $this>
     */
    public function mailDomain(): BelongsTo
    {
        return $this->belongsTo(MailDomain::class);
    }

    /**
     * @return HasOne<MailAutoreply, $this>
     */
    public function autoreply(): HasOne
    {
        return $this->hasOne(MailAutoreply::class);
    }

    /**
     * In the order they run.
     *
     * @return HasMany<MailFilter, $this>
     */
    public function filters(): HasMany
    {
        return $this->hasMany(MailFilter::class)->orderBy('id');
    }

    /**
     * @return HasMany<WebmailSession, $this>
     */
    public function webmailSessions(): HasMany
    {
        return $this->hasMany(WebmailSession::class);
    }
}
