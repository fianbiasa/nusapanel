<?php

namespace App\Models;

use App\Enums\DatabaseStatus;
use Database\Factories\DatabaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A MariaDB database of a hosting account, named <username>_<name>. Its
 * size is measured every fifteen minutes with the disk usage.
 *
 * `user_id`, `status` and the size are deliberately not mass assignable.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property DatabaseStatus $status
 * @property int|null $size_bytes
 * @property Carbon|null $measured_at
 * @property string|null $import_status How the last import went: running, done or failed.
 * @property string|null $import_error What MariaDB said when the last import failed.
 * @property Carbon|null $imported_at When the last import finished.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
class Database extends Model
{
    /** @use HasFactory<DatabaseFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DatabaseStatus::class,
            'size_bytes' => 'integer',
            'measured_at' => 'datetime',
            'imported_at' => 'datetime',
        ];
    }

    /**
     * The hosting account the database belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The database users with access to it.
     *
     * @return BelongsToMany<DatabaseUser, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(DatabaseUser::class);
    }
}
