<?php

namespace App\Models;

use App\Enums\Role;
use App\Services\QuotaService;
use App\Support\AccountLimits;
use Database\Factories\PackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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
 * `disk_mb` and `inodes` as the account's disk quota, and the
 * {@see self::RESOURCE_LIMITS} with cgroups ({@see QuotaService}).
 *
 * A package belongs to the administrators (its owner is an admin, or was
 * one) or to one reseller, whose accounts are the only ones put on it.
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
 * @property int|null $pointers Domain pointers.
 * @property int|null $email_accounts
 * @property int|null $email_forwarders
 * @property int|null $databases
 * @property int|null $ftp_accounts
 * @property int|null $cron_jobs
 * @property bool $ssl
 * @property bool $cgi Whether the accounts may run CGI scripts, which can start any program as the account.
 * @property bool $ssh Whether the accounts may turn on SSH: a shell, in a jail of their own.
 * @property int|null $cpu_percent CPU time in percent of one CPU: 200 is two CPUs.
 * @property int|null $memory_mb
 * @property int|null $max_processes Processes and threads at once.
 * @property int|null $io_weight Share of disk IO, 1 to 10000; null is the server's default of 100.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'description', 'disk_mb', 'inodes', 'bandwidth_mb', 'domains', 'subdomains', 'pointers', 'email_accounts', 'email_forwarders', 'databases', 'ftp_accounts', 'cron_jobs', 'ssl', 'cgi', 'ssh', 'cpu_percent', 'memory_mb', 'max_processes', 'io_weight'])]
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
        'pointers',
        'email_accounts',
        'email_forwarders',
        'databases',
        'ftp_accounts',
        'cron_jobs',
    ];

    /**
     * What each account may use of the server at once, in the order the
     * panel shows them. Unlike {@see self::LIMITS} they are not shared out
     * by a reseller package: they only hold back the account itself.
     *
     * @var list<string>
     */
    public const RESOURCE_LIMITS = [
        'cpu_percent',
        'memory_mb',
        'max_processes',
        'io_weight',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'ssl' => true,
        'cgi' => false,
        'ssh' => false,
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
            ...array_fill_keys(self::RESOURCE_LIMITS, 'integer'),
            'ssl' => 'boolean',
            'cgi' => 'boolean',
            'ssh' => 'boolean',
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
     * The packages the accounts of owner may be put on: a reseller's own
     * packages, or for an administrator those of the administrators.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function handedOutBy(Builder $query, User $owner): void
    {
        if ($owner->isReseller()) {
            $query->where('owner_id', $owner->id);

            return;
        }

        $query->where(fn (Builder $query) => $query
            ->whereNull('owner_id')
            ->orWhereIn('owner_id', User::query()->select('id')->where('role', Role::Admin)));
    }

    /**
     * The admin or reseller who created the package.
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
