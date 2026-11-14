<?php

namespace App\Models;

use App\Enums\ServerBackupStatus;
use Database\Factories\ServerBackupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A backup of a hosting account the agent keeps outside its home, in the
 * folder of config('hosting.backups.dir'), as name.
 *
 * @property int $id
 * @property int|null $backup_run_id
 * @property int|null $user_id Null once the account is deleted.
 * @property string $username
 * @property string $name
 * @property ServerBackupStatus $status
 * @property bool $files
 * @property bool $email
 * @property list<string> $databases
 * @property int|null $size_bytes
 * @property int $skipped Files left out because they could not be read.
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['backup_run_id', 'user_id', 'username', 'name', 'status', 'files', 'email', 'databases'])]
class ServerBackup extends Model
{
    /** @use HasFactory<ServerBackupFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ServerBackupStatus::class,
            'files' => 'boolean',
            'email' => 'boolean',
            'databases' => 'array',
            'size_bytes' => 'integer',
            'skipped' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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
     * @return BelongsTo<BackupRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(BackupRun::class, 'backup_run_id');
    }

    /**
     * Where the backup's file is.
     */
    public function path(): string
    {
        return rtrim((string) config('hosting.backups.dir'), '/').'/'.$this->name;
    }
}
