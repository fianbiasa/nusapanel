<?php

namespace App\Models;

use App\Services\ResourceUsageService;
use Database\Factories\ResourceUsageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a hosting account used of the server on one day: CPU time and the
 * most memory it held at once; {@see ResourceUsageService} adds to
 * today's. The usage graphs read them.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $date
 * @property int $cpu_ms
 * @property int $memory_peak_mb
 */
#[Table('resource_usages', timestamps: false)]
#[Fillable(['user_id', 'date', 'cpu_ms', 'memory_peak_mb'])]
class ResourceUsage extends Model
{
    /** @use HasFactory<ResourceUsageFactory> */
    use HasFactory, Prunable;

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
            'cpu_ms' => 'integer',
            'memory_peak_mb' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
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
