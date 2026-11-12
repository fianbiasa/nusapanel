<?php

namespace App\Models;

use Database\Factories\SshKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A public key an account added in the panel. The agent writes every key
 * of the account to its ~/.ssh/authorized_keys, between lines of its own,
 * so the account can log in with it over SFTP and, with SSH, to its shell.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $public_key The key as a line of authorized_keys.
 * @property string $fingerprint SHA256:… as ssh-keygen -l shows it.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'public_key', 'fingerprint'])]
class SshKey extends Model
{
    /** @use HasFactory<SshKeyFactory> */
    use HasFactory;

    /**
     * The account the key logs in to.
     *
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
