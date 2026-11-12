<?php

namespace App\Models;

use App\Services\QuotaService;
use Database\Factories\UsageSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A hosting account's disk space and inodes on one day, as last measured
 * that day; {@see QuotaService} keeps today's up to date. The usage graphs
 * read them.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $date
 * @property int $disk_kb
 * @property int $inodes
 */
#[Table('usage_snapshots', timestamps: false)]
#[Fillable(['user_id', 'date', 'disk_kb', 'inodes'])]
class UsageSnapshot extends Model
{
    /** @use HasFactory<UsageSnapshotFactory> */
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
            'disk_kb' => 'integer',
            'inodes' => 'integer',
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
