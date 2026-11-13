<?php

namespace App\Models;

use Database\Factories\DnsTemplateRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One record of the template new DNS zones start with. The administrators'
 * template has no owner; a reseller with records of its own uses those for
 * the zones of its accounts instead. {domain} and {ip} in the content stand
 * for the zone and the server's address.
 *
 * `owner_id` is deliberately not mass assignable.
 *
 * @property int $id
 * @property int|null $owner_id
 * @property string $name Relative to the zone; "@" is the zone itself.
 * @property string $type
 * @property string $content
 * @property int $ttl
 * @property int|null $priority MX and SRV only.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'type', 'content', 'ttl', 'priority'])]
class DnsTemplateRecord extends Model
{
    /** @use HasFactory<DnsTemplateRecordFactory> */
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
     * The reseller whose template this is; null for the administrators'.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * The records of owner's template: a reseller's own, or the
     * administrators' (null).
     *
     * @return Builder<self>
     */
    public static function ofTemplate(?User $owner): Builder
    {
        return $owner === null
            ? self::query()->whereNull('owner_id')
            : self::query()->where('owner_id', $owner->id);
    }
}
