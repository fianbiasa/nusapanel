<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\IpAddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An extra IP address of the server, which hosting accounts can have for
 * their websites instead of the server's main address. It belongs to the
 * administrators (no owner, or an admin) or to one reseller, whose
 * accounts are the only ones that may have it; a dedicated address serves
 * one account at most.
 *
 * `owner_id` and `added` are deliberately not mass assignable.
 *
 * @property int $id
 * @property string $address
 * @property int $version 4 or 6.
 * @property int $prefix
 * @property int|null $owner_id
 * @property bool $dedicated
 * @property bool $added Whether the agent put it on the network interface, and so takes it off again.
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['dedicated', 'note'])]
class IpAddress extends Model
{
    /** @use HasFactory<IpAddressFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'dedicated' => false,
        'added' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'prefix' => 'integer',
            'dedicated' => 'boolean',
            'added' => 'boolean',
        ];
    }

    /**
     * The reseller (or administrator) whose accounts may have the address.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * The hosting accounts whose websites are served on the address.
     *
     * @return Builder<User>
     */
    public function accounts(): Builder
    {
        $column = $this->version === 6 ? 'ipv6_address_id' : 'ipv4_address_id';

        return User::query()->where($column, $this->id);
    }

    /**
     * Whether the accounts of owner may have the address: a reseller's own
     * addresses, or for an administrator those of the administrators.
     */
    public function isHandedOutBy(User $owner): bool
    {
        if ($owner->isReseller()) {
            return $this->owner_id === $owner->id;
        }

        return $this->owner_id === null || $this->owner?->role === Role::Admin;
    }

    /**
     * The addresses the accounts of owner may have.
     *
     * @return Builder<self>
     */
    public static function handedOutBy(User $owner): Builder
    {
        if ($owner->isReseller()) {
            return self::query()->where('owner_id', $owner->id);
        }

        return self::query()->where(fn (Builder $query) => $query
            ->whereNull('owner_id')
            ->orWhereIn('owner_id', User::query()->select('id')->where('role', Role::Admin)));
    }
}
