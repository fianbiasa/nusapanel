<?php

namespace App\Models;

use Database\Factories\LoginKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * A key an account uses the API with, instead of signing in. Only the
 * SHA-256 of the key is kept; the panel shows the key once, when it is
 * made. A key only does what its permissions allow, from its allowed
 * addresses, until it expires.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $key_hash SHA-256 of the key, hex.
 * @property string $key_prefix The start of the key, to tell keys apart.
 * @property list<string> $permissions
 * @property list<string>|null $allowed_ips Addresses and networks the key works from; null is anywhere.
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_used_at
 * @property string|null $last_used_ip
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'permissions', 'allowed_ips', 'expires_at'])]
#[Hidden(['key_hash'])]
class LoginKey extends Model
{
    /** @use HasFactory<LoginKeyFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'allowed_ips' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * The account the key acts as.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function allows(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function allowsAddress(?string $ip): bool
    {
        return $this->allowed_ips === null || $this->allowed_ips === []
            || ($ip !== null && IpUtils::checkIp($ip, $this->allowed_ips));
    }
}
