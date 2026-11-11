<?php

namespace App\Models;

use App\Enums\MailDomainStatus;
use Database\Factories\MailDomainFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A domain that takes email on this server. Postfix and Dovecot see it
 * through the v_mail_domains view while it is active.
 *
 * @property int $id
 * @property int $domain_id
 * @property string $dkim_selector
 * @property string|null $dkim_public_key Base64 of the DER public key, for the DKIM DNS record.
 * @property string|null $catchall_target Where mail for addresses the domain does not have goes; null refuses it.
 * @property MailDomainStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MailDomain extends Model
{
    /** @use HasFactory<MailDomainFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MailDomainStatus::class,
        ];
    }

    /**
     * The domain mail is taken for.
     *
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * @return HasMany<Mailbox, $this>
     */
    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class);
    }

    /**
     * @return HasMany<MailForwarder, $this>
     */
    public function forwarders(): HasMany
    {
        return $this->hasMany(MailForwarder::class);
    }
}
