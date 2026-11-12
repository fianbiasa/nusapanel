<?php

namespace App\Models;

use App\Support\CryptPassword;
use Database\Factories\ProtectedDirectoryUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A login of a protected folder. Only the SHA-512 crypt hash of its
 * password is kept, the form both Nginx and Apache check.
 *
 * @property int $id
 * @property int $protected_directory_id
 * @property string $username
 * @property string $password_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['username'])]
#[Hidden(['password_hash'])]
class ProtectedDirectoryUser extends Model
{
    /** @use HasFactory<ProtectedDirectoryUserFactory> */
    use HasFactory;

    /**
     * The folder the login opens.
     *
     * @return BelongsTo<ProtectedDirectory, $this>
     */
    public function protectedDirectory(): BelongsTo
    {
        return $this->belongsTo(ProtectedDirectory::class);
    }

    /**
     * Give the login a new password; only its hash is kept.
     */
    public function setPassword(string $password): void
    {
        $this->password_hash = CryptPassword::hash($password);
    }
}
