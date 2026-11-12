<?php

namespace App\Models;

use App\Support\DatabaseHost;
use Database\Factories\DatabaseRemoteHostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A host besides the server that the database users of an account may
 * connect from, in the form MariaDB keeps it ({@see DatabaseHost}). It
 * only counts while the administrator lets MariaDB answer from outside.
 *
 * @property int $id
 * @property int $user_id
 * @property string $host
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['host'])]
class DatabaseRemoteHost extends Model
{
    /** @use HasFactory<DatabaseRemoteHostFactory> */
    use HasFactory;

    /**
     * The account that allows the host.
     *
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
