<?php

namespace App\Support;

use App\Enums\DomainStatus;
use App\Models\Package;
use App\Models\User;
use Illuminate\Contracts\Support\Arrayable;

/**
 * What a hosting account's package allows, and how much of it the account
 * uses. Both enforcement and the pages read limits from here.
 *
 * Accounts without a package (admins, resellers and accounts created before
 * packages existed) have no limits and may use SSL. Only usage the panel
 * can count today is measured (domains); the other limits are stored and
 * shown, and are enforced once their features exist.
 *
 * @implements Arrayable<string, mixed>
 */
final class AccountLimits implements Arrayable
{
    private ?int $domainCount = null;

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
     * cannot measure it yet. Domains that are being removed do not count.
     */
    public function usage(string $key): ?int
    {
        return match ($key) {
            'domains' => $this->domainCount ??= $this->account->domains()
                ->where('status', '!=', DomainStatus::Deleting)
                ->count(),
            default => null,
        };
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
     * The limits and usage for the pages.
     *
     * @return array{package: string|null, ssl: bool, quotas: array<string, array{limit: int|null, used: int|null}>}
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
        ];
    }
}
