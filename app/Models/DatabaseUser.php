<?php

namespace App\Models;

use App\Enums\DatabaseStatus;
use Database\Factories\DatabaseUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A MariaDB user of a hosting account, named <username>_<name>, that may
 * reach the account's databases from this server only. The password is
 * kept as its mysql_native_password hash, which is all the agent ever
 * gets.
 *
 * `user_id`, `status` and the hash are deliberately not mass assignable.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $password_hash
 * @property DatabaseStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
#[Hidden(['password_hash'])]
class DatabaseUser extends Model
{
    /** @use HasFactory<DatabaseUserFactory> */
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
        ];
    }

    /**
     * The mysql_native_password hash of password: "*" and the uppercase
     * hex SHA-1 of the binary SHA-1 of it.
     */
    public static function hashPassword(string $password): string
    {
        return '*'.strtoupper(sha1(sha1($password, true)));
    }

    /**
     * The hosting account the user belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The databases the user has access to.
     *
     * @return BelongsToMany<Database, $this>
     */
    public function databases(): BelongsToMany
    {
        return $this->belongsToMany(Database::class);
    }
}
