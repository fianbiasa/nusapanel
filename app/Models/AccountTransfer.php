<?php

namespace App\Models;

use Database\Factories\AccountTransferFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A hosting account moved here from another RAYXO server: that server
 * makes a backup of it, this one downloads it, keeps it like its own
 * backups, and makes the account again from it.
 *
 * @property int $id
 * @property int|null $actor_id
 * @property string $source_url The other panel, e.g. https://old.example.com:2222.
 * @property string|null $api_key The other panel's login key; null once the move ended.
 * @property bool $verify_tls
 * @property string $username
 * @property string $status One of the STATUS_ constants.
 * @property int|null $remote_backup_id
 * @property int|null $server_backup_id
 * @property int|null $user_id The account made here.
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Hidden(['api_key'])]
class AccountTransfer extends Model
{
    /** @use HasFactory<AccountTransferFactory> */
    use HasFactory;

    public const STATUS_QUEUED = 'queued';

    /** The other server makes the backup. */
    public const STATUS_BACKING_UP = 'backing_up';

    public const STATUS_DOWNLOADING = 'downloading';

    /** The account is being made here; see its account restore. */
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
            'api_key' => 'encrypted',
            'verify_tls' => 'boolean',
        ];
    }

    /**
     * Whether the move is still going on here.
     */
    public function isGoing(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_BACKING_UP, self::STATUS_DOWNLOADING], true);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<ServerBackup, $this>
     */
    public function backup(): BelongsTo
    {
        return $this->belongsTo(ServerBackup::class, 'server_backup_id');
    }
}
