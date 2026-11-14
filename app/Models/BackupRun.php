<?php

namespace App\Models;

use App\Enums\BackupRunStatus;
use Database\Factories\BackupRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One pass of the automatic backups over every hosting account.
 *
 * @property int $id
 * @property string $trigger "schedule" or "manual".
 * @property BackupRunStatus $status
 * @property int $accounts
 * @property int $succeeded
 * @property int $failed
 * @property string|null $message Why it was skipped.
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['trigger', 'status', 'accounts', 'succeeded', 'failed', 'message', 'started_at', 'finished_at'])]
class BackupRun extends Model
{
    /** @use HasFactory<BackupRunFactory> */
    use HasFactory;

    public const TRIGGER_SCHEDULE = 'schedule';

    public const TRIGGER_MANUAL = 'manual';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BackupRunStatus::class,
            'accounts' => 'integer',
            'succeeded' => 'integer',
            'failed' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ServerBackup, $this>
     */
    public function backups(): HasMany
    {
        return $this->hasMany(ServerBackup::class);
    }
}
