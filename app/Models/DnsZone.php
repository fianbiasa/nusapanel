<?php

namespace App\Models;

use Database\Factories\DnsZoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A DNS zone this server serves, for a domain or pointer of a hosting
 * account. The panel keeps its records; the agent copies the whole zone to
 * PowerDNS (dns.sync_zone).
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $domain_id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
class DnsZone extends Model
{
    /** @use HasFactory<DnsZoneFactory> */
    use HasFactory;

    /**
     * The hosting account the zone belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The domain or pointer the zone is for.
     *
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * @return HasMany<DnsRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(DnsRecord::class);
    }
}
