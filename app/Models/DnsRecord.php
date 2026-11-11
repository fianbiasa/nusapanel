<?php

namespace App\Models;

use Database\Factories\DnsRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One record of a DNS zone, in the form the agent takes (dns.sync_zone).
 *
 * @property int $id
 * @property int $dns_zone_id
 * @property string $name Absolute without the trailing dot, like www.example.com.
 * @property string $type A, AAAA, CNAME, MX, TXT, SRV, CAA or NS.
 * @property string $content The value: an address, a host name, the text itself (unquoted), "weight port target" for SRV or "flags tag value" for CAA.
 * @property int $ttl
 * @property int|null $priority MX and SRV only.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'type', 'content', 'ttl', 'priority'])]
class DnsRecord extends Model
{
    /** @use HasFactory<DnsRecordFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ttl' => 'integer',
            'priority' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DnsZone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(DnsZone::class, 'dns_zone_id');
    }

    /**
     * The record as the agent takes it.
     *
     * @return array{name: string, type: string, content: string, ttl: int, priority: int}
     */
    public function toAgent(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'content' => $this->content,
            'ttl' => $this->ttl,
            'priority' => $this->priority ?? 0,
        ];
    }
}
