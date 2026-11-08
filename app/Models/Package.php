<?php

namespace App\Models;

use App\Services\QuotaService;
use App\Support\AccountLimits;
use Database\Factories\PackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A hosting package: what the hosting accounts on it may use. Every
 * quantity limit is null when the package does not limit it.
 * {@see AccountLimits} reads the limits of an account; the server enforces
 * `disk_mb` and `inodes` as the account's disk quota ({@see QuotaService}).
 *
 * `owner_id` is deliberately not mass assignable.
 *
 * @property int $id
 * @property int|null $owner_id
 * @property string $name
 * @property string|null $description
 * @property int|null $disk_mb
 * @property int|null $inodes The number of files and directories.
 * @property int|null $bandwidth_mb
 * @property int|null $domains
 * @property int|null $subdomains
 * @property int|null $email_accounts
 * @property int|null $databases
 * @property int|null $ftp_accounts
 * @property int|null $cron_jobs
 * @property bool $ssl
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'description', 'disk_mb', 'inodes', 'bandwidth_mb', 'domains', 'subdomains', 'email_accounts', 'databases', 'ftp_accounts', 'cron_jobs', 'ssl'])]
class Package extends Model
{
    /** @use HasFactory<PackageFactory> */
    use HasFactory;

    /**
     * The quantity limits, in the order the panel shows them.
     *
     * @var list<string>
     */
    public const LIMITS = [
        'disk_mb',
        'inodes',
        'bandwidth_mb',
        'domains',
        'subdomains',
        'email_accounts',
        'databases',
        'ftp_accounts',
        'cron_jobs',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'ssl' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...array_fill_keys(self::LIMITS, 'integer'),
            'ssl' => 'boolean',
        ];
    }

    /**
     * The limit of the given quantity, or null when it is unlimited.
     */
    public function limit(string $key): ?int
    {
        if (! in_array($key, self::LIMITS, true)) {
            throw new InvalidArgumentException("Unknown package limit [{$key}].");
        }

        $limit = $this->getAttribute($key);

        return $limit === null ? null : (int) $limit;
    }

    /**
     * The admin who created the package.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * The hosting accounts on this package.
     *
     * @return HasMany<User, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
