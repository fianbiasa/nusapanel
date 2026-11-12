<?php

namespace App\Models;

use App\Services\BandwidthService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one website (domain, subdomain or pointer) moved on one day, in
 * bytes: what it sent and the mail of its domain.
 * {@see BandwidthService} adds to it from the agent's counts.
 *
 * @property int $id
 * @property int $domain_id
 * @property Carbon $date
 * @property int $bytes
 */
#[Table('domain_bandwidths', timestamps: false)]
#[Fillable(['domain_id', 'date', 'bytes'])]
class DomainBandwidth extends Model
{
    use Prunable;

    /**
     * Dates are stored as days, the same on SQLite and MariaDB.
     *
     * @var string
     */
    protected $dateFormat = 'Y-m-d';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * Days older than about thirteen months are no longer kept.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('date', '<', now()->subDays(400)->toDateString());
    }
}
