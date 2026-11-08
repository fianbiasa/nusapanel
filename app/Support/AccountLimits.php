<?php

namespace App\Support;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Models\Package;
use App\Models\User;
use App\Services\QuotaService;
use Illuminate\Contracts\Support\Arrayable;

/**
 * What a hosting account's package allows, and how much of it the account
 * uses. Both enforcement and the pages read limits from here.
 *
 * Accounts without a package (admins, resellers and accounts created before
 * packages existed) have no limits and may use SSL. The panel counts the
 * account's domains, subdomains and pointers itself; disk space and inodes
 * are measured by the
 * server ({@see QuotaService}), which also enforces their limits, so the
 * panel never blocks anything on them. The other limits are stored and
 * shown, and are enforced once their features exist.
 *
 * @implements Arrayable<string, mixed>
 */
final class AccountLimits implements Arrayable
{
    /**
     * The number of websites of each type, once counted.
     *
     * @var array<string, int>|null
     */
    private ?array $websiteCounts = null;

    private function __construct(private readonly User $account) {}

    public static function for(User $account): self
    {
        return new self($account);
    }

    /**
     * The limit of the given quantity, or null when it is unlimited.
     */
    public function limit(string $key): ?int
    {
        return $this->account->package?->limit($key);
    }

    /**
     * How much of the quantity the account uses, or null while the panel
     * cannot measure it (yet). Websites that are being removed do not count;
     * disk space is in whole megabytes, rounded up, as last measured.
     */
    public function usage(string $key): ?int
    {
        return match ($key) {
            'domains' => $this->websiteCount(DomainType::Domain),
            'subdomains' => $this->websiteCount(DomainType::Subdomain),
            'pointers' => $this->websiteCount(DomainType::Pointer),
            'disk_mb' => $this->account->diskUsage?->diskMegabytes(),
            'inodes' => $this->account->diskUsage?->inodes,
            default => null,
        };
    }

    /**
     * The number of websites of the type the account has, not counting
     * those being removed. All types are counted in one query.
     */
    private function websiteCount(DomainType $type): int
    {
        $this->websiteCounts ??= $this->account->domains()
            ->where('status', '!=', DomainStatus::Deleting)
            ->toBase()
            ->selectRaw('type, count(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        return $this->websiteCounts[$type->value] ?? 0;
    }

    /**
     * Whether the account may not add any more of the quantity. Accounts
     * moved to a smaller package can be over their limit.
     */
    public function isReached(string $key): bool
    {
        $limit = $this->limit($key);

        return $limit !== null && ($this->usage($key) ?? 0) >= $limit;
    }

    /**
     * Whether the account may request SSL certificates.
     */
    public function allowsSsl(): bool
    {
        return $this->account->package === null || $this->account->package->ssl;
    }

    /**
     * The limits and usage for the pages: when disk usage was last measured,
     * and whether the server enforces disk quotas (null until it reported).
     *
     * @return array{package: string|null, ssl: bool, quotas: array<string, array{limit: int|null, used: int|null}>, measured_at: string|null, quota_enabled: bool|null}
     */
    public function toArray(): array
    {
        $quotas = [];

        foreach (Package::LIMITS as $key) {
            $quotas[$key] = ['limit' => $this->limit($key), 'used' => $this->usage($key)];
        }

        return [
            'package' => $this->account->package?->name,
            'ssl' => $this->allowsSsl(),
            'quotas' => $quotas,
            'measured_at' => $this->account->diskUsage?->measured_at->toIso8601String(),
            'quota_enabled' => QuotaService::isEnabled(),
        ];
    }
}
