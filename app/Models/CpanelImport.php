<?php

namespace App\Models;

use Database\Factories\CpanelImportFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A hosting account imported from a cPanel backup the administrator copied
 * into the backups folder: the agent reads it, turns it into a RAYXO
 * backup, and the account is made again from that (see
 * CpanelImportService).
 *
 * @property int $id
 * @property int|null $actor_id
 * @property string $file The cPanel backup in the backups folder.
 * @property string|null $username The username here; null takes cPanel's.
 * @property string|null $email Null takes the cPanel account's contact address.
 * @property string|null $password_hash The new password, hashed; null once the account exists.
 * @property int|null $package_id
 * @property string $status One of the STATUS_ constants.
 * @property string|null $cpanel_user The username in cPanel, once read.
 * @property int|null $server_backup_id The RAYXO backup it became.
 * @property int|null $user_id The account made here.
 * @property list<string>|null $notes What was left out or changed.
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Hidden(['password_hash'])]
class CpanelImport extends Model
{
    /** @use HasFactory<CpanelImportFactory> */
    use HasFactory;

    public const STATUS_QUEUED = 'queued';

    /** The agent reads the cPanel backup through. */
    public const STATUS_READING = 'reading';

    /** The agent writes the RAYXO backup. */
    public const STATUS_CONVERTING = 'converting';

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
            'notes' => 'array',
        ];
    }

    /**
     * Whether the import is still going on here.
     */
    public function isGoing(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_READING, self::STATUS_CONVERTING], true);
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
