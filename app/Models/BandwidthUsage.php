<?php

namespace App\Models;

use App\Services\BandwidthService;
use Database\Factories\BandwidthUsageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a hosting account moved on one day, in bytes: what its websites
 * sent, the mail of its domains, and its FTP logins' transfers.
 * {@see BandwidthService} adds to it from the agent's counts.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $date
 * @property int $web_bytes
 * @property int $mail_bytes
 * @property int $ftp_bytes
 */
#[Table('bandwidth_usages', timestamps: false)]
#[Fillable(['user_id', 'date', 'web_bytes', 'mail_bytes', 'ftp_bytes'])]
class BandwidthUsage extends Model
{
    /** @use HasFactory<BandwidthUsageFactory> */
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
            'web_bytes' => 'integer',
            'mail_bytes' => 'integer',
            'ftp_bytes' => 'integer',
        ];
    }

    /**
     * The day's traffic of every kind together.
     */
    public function totalBytes(): int
    {
        return $this->web_bytes + $this->mail_bytes + $this->ftp_bytes;
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
