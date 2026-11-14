<?php

namespace App\Models;

use App\Support\ResellerLimits;
use Database\Factories\ResellerPackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * What a reseller may give out to its hosting accounts, in total: how many
 * accounts, and the same quantities as a hosting package. Every limit is
 * null when the package does not limit it. Without oversell, the packages
 * of a reseller's accounts together may not give out more than these
 * limits; with it they may, and only the number of accounts is a hard
 * limit. {@see ResellerLimits} reads and checks them.
 *
 * The resource caps ({@see Package::RESOURCE_LIMITS}) are not shared out:
 * each is the most one account of the reseller may get, whatever its
 * hosting package says.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int|null $accounts
 * @property int|null $disk_mb
 * @property int|null $inodes
 * @property int|null $bandwidth_mb
 * @property int|null $domains
 * @property int|null $subdomains
 * @property int|null $pointers
 * @property int|null $email_accounts
 * @property int|null $email_forwarders
 * @property int|null $databases
 * @property int|null $ftp_accounts
 * @property int|null $cron_jobs
 * @property bool $ssl Whether the reseller's accounts may have SSL.
 * @property bool $cgi Whether the reseller's accounts may run CGI scripts.
 * @property bool $ssh Whether the reseller's accounts may turn on SSH.
 * @property bool $oversell Whether the reseller may give out more than its limits.
 * @property int|null $cpu_percent The most CPU an account may get, in percent of one CPU.
 * @property int|null $memory_mb The most memory an account may get.
 * @property int|null $max_processes The most processes an account may run.
 * @property int|null $io_weight The highest disk IO priority an account may get.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'description', 'accounts', 'disk_mb', 'inodes', 'bandwidth_mb', 'domains', 'subdomains', 'pointers', 'email_accounts', 'email_forwarders', 'databases', 'ftp_accounts', 'cron_jobs', 'ssl', 'cgi', 'ssh', 'oversell', 'cpu_percent', 'memory_mb', 'max_processes', 'io_weight'])]
class ResellerPackage extends Model
{
    /** @use HasFactory<ResellerPackageFactory> */
    use HasFactory;

    /**
     * The quantity limits, in the order the panel shows them: the number
     * of accounts, then those of a hosting package.
     *
     * @var list<string>
     */
    public const LIMITS = ['accounts', ...Package::LIMITS];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'ssl' => true,
        'cgi' => false,
        'ssh' => false,
        'oversell' => false,
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
            ...array_fill_keys(Package::RESOURCE_LIMITS, 'integer'),
            'ssl' => 'boolean',
            'cgi' => 'boolean',
            'ssh' => 'boolean',
            'oversell' => 'boolean',
        ];
    }

    /**
     * The limit of the given quantity, or null when it is unlimited.
     */
    public function limit(string $key): ?int
    {
        if (! in_array($key, self::LIMITS, true)) {
            throw new InvalidArgumentException("Unknown reseller package limit [{$key}].");
        }

        $limit = $this->getAttribute($key);

        return $limit === null ? null : (int) $limit;
    }

    /**
     * The most of the resource (one of {@see Package::RESOURCE_LIMITS}) an
     * account of the reseller may get, or null when it is not capped.
     */
    public function resourceCap(string $key): ?int
    {
        if (! in_array($key, Package::RESOURCE_LIMITS, true)) {
            throw new InvalidArgumentException("Unknown resource limit [{$key}].");
        }

        $cap = $this->getAttribute($key);

        return $cap === null ? null : (int) $cap;
    }

    /**
     * The resellers on this package.
     *
     * @return HasMany<User, $this>
     */
    public function resellers(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
