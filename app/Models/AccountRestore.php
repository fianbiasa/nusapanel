<?php

namespace App\Models;

use Database\Factories\AccountRestoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A deleted account made again from a backup the server keeps. It waits
 * until the account is created, then brings back what the backup holds.
 *
 * @property int $id
 * @property int|null $server_backup_id
 * @property int $user_id
 * @property int|null $actor_id
 * @property string $status One of the STATUS_ constants.
 * @property list<string>|null $skipped What could not come back, and why.
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AccountRestore extends Model
{
    /** @use HasFactory<AccountRestoreFactory> */
    use HasFactory;

    /** The account is being created. */
    public const STATUS_WAITING = 'waiting';

    /** The account is active; its data is being restored. */
    public const STATUS_RESTORING = 'restoring';

    public const STATUS_FAILED = 'failed';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'skipped' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ServerBackup, $this>
     */
    public function backup(): BelongsTo
    {
        return $this->belongsTo(ServerBackup::class, 'server_backup_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
