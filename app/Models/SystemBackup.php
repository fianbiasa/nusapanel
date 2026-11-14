<?php

namespace App\Models;

use App\Enums\ServerBackupStatus;
use Database\Factories\SystemBackupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A backup of the panel itself: its database, its configuration, the DKIM
 * keys and the files people uploaded. The agent keeps it for root alone;
 * setup.sh brings a panel back from it on a new server.
 *
 * @property int $id
 * @property string $name
 * @property ServerBackupStatus $status
 * @property int|null $size_bytes
 * @property string|null $error
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'status'])]
class SystemBackup extends Model
{
    /** @use HasFactory<SystemBackupFactory> */
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
            'size_bytes' => 'integer',
            'finished_at' => 'datetime',
        ];
    }
}
