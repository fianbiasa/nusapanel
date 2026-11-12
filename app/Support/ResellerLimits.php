<?php

namespace App\Support;

use App\Enums\AccountStatus;
use App\Enums\DatabaseStatus;
use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Enums\Role;
use App\Models\AccountUsage;
use App\Models\BandwidthUsage;
use App\Models\CronJob;
use App\Models\Database;
use App\Models\Domain;
use App\Models\FtpAccount;
use App\Models\Mailbox;
use App\Models\MailForwarder;
use App\Models\Package;
use App\Models\ResellerPackage;
use App\Models\User;
use App\Services\BandwidthService;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What a reseller's package allows it to give out, how much its hosting
 * accounts use, and how much their packages give out ("allocated").
 *
 * The number of accounts is always a hard limit. Without oversell, the
 * packages of the reseller's accounts together may not give out more than
 * the reseller's limits, so every account needs a package that limits what
 * the reseller's package limits; that bounds what the accounts can use.
 * With oversell, packages may give out more, and the usage is only shown.
 * A reseller without a package is not limited.
 *
 * @implements Arrayable<string, mixed>
 */
final class ResellerLimits implements Arrayable
{
    /**
     * The reseller's hosting accounts, once loaded.
     *
     * @var Collection<int, User>|null
     */
    private ?Collection $accounts = null;

    private function __construct(private readonly User $reseller) {}

    public static function for(User $reseller): self
    {
        return new self($reseller);
    }

    /**
     * The limit of the given quantity, or null when it is unlimited.
     */
    public function limit(string $key): ?int
    {
        return $this->reseller->resellerPackage?->limit($key);
    }

    public function oversells(): bool
    {
        return $this->reseller->resellerPackage === null || $this->reseller->resellerPackage->oversell;
    }

    public function allowsSsl(): bool
    {
        return $this->reseller->resellerPackage === null || $this->reseller->resellerPackage->ssl;
    }

    /**
     * How much of the quantity the reseller's accounts use together, or
     * null while it is not measured. Disk space is in megabytes.
     */
    public function usage(string $key): ?int
    {
        $accounts = $this->accountIds();

        return match ($key) {
            'accounts' => $this->accounts()->count(),
            'domains', 'subdomains', 'pointers' => Domain::query()
                ->whereIn('user_id', $accounts)
                ->where('type', match ($key) {
                    'domains' => DomainType::Domain,
                    'subdomains' => DomainType::Subdomain,
                    default => DomainType::Pointer,
                })
                ->where('status', '!=', DomainStatus::Deleting)
                ->count(),
            'databases' => Database::query()
                ->whereIn('user_id', $accounts)
                ->where('status', '!=', DatabaseStatus::Deleting)
                ->count(),
            'email_accounts' => Mailbox::query()
                ->whereHas('mailDomain.domain', fn (Builder $query) => $query->whereIn('user_id', $accounts))
                ->count(),
            'email_forwarders' => MailForwarder::query()
                ->whereHas('mailDomain.domain', fn (Builder $query) => $query->whereIn('user_id', $accounts))
                ->count(),
            'ftp_accounts' => FtpAccount::query()->whereIn('user_id', $accounts)->count(),
            'cron_jobs' => CronJob::query()->whereIn('user_id', $accounts)->count(),
            'disk_mb' => (int) ceil(((int) AccountUsage::query()->whereIn('user_id', $accounts)->sum('disk_kb')) / 1024),
            'inodes' => (int) AccountUsage::query()->whereIn('user_id', $accounts)->sum('inodes'),
            'bandwidth_mb' => BandwidthService::megabytes((int) BandwidthUsage::query()
                ->whereIn('user_id', $accounts)
                ->where('date', '>=', now()->startOfMonth()->toDateString())
                ->sum(DB::raw('web_bytes + mail_bytes + ftp_bytes'))),
            default => null,
        };
    }

    /**
     * How much of the quantity the packages of the reseller's accounts give
     * out together, or null when an account's package does not limit it
     * (or it has none). The account except, if given, is left out.
     */
    public function allocated(string $key, ?User $except = null): ?int
    {
        $accounts = $this->accounts()->reject(fn (User $account): bool => $except !== null && $account->is($except));

        if ($key === 'accounts') {
            return $accounts->count();
        }

        $total = 0;

        foreach ($accounts as $account) {
            $limit = $account->package?->limit($key);

            if ($limit === null) {
                return null;
            }

            $total += $limit;
        }

        return $total;
    }

    /**
     * Refuse to give the account (a new one, or except moving to the
     * package or to this reseller) the package, when the reseller may not.
     *
     * @throws ValidationException
     */
    public function ensureCanGiveOut(?Package $package, ?User $except = null, string $errorKey = 'package_id'): void
    {
        $accountLimit = $this->limit('accounts');
        $isNew = $except === null || ! $this->accounts()->contains(fn (User $account): bool => $account->is($except));

        if ($isNew && $accountLimit !== null && $this->allocated('accounts') >= $accountLimit) {
            throw ValidationException::withMessages([
                $errorKey => __("The reseller's package allows only :limit accounts.", ['limit' => $accountLimit]),
            ]);
        }

        if ($this->oversells()) {
            return;
        }

        foreach (Package::LIMITS as $key) {
            $limit = $this->limit($key);

            if ($limit === null) {
                continue;
            }

            $given = $package?->limit($key);

            if ($given === null) {
                throw ValidationException::withMessages([
                    $errorKey => __("The reseller's package limits :item, so the account needs a package that limits it too.", ['item' => self::label($key)]),
                ]);
            }

            $allocated = $this->allocated($key, $except);

            if ($allocated === null) {
                throw ValidationException::withMessages([
                    $errorKey => __("An account of the reseller is not limited in :item, so the reseller's package allows no more of it.", ['item' => self::label($key)]),
                ]);
            }

            $total = $allocated + $given;

            if ($total > $limit) {
                throw ValidationException::withMessages([
                    $errorKey => __("With this package the reseller's accounts would get :total :item, but the reseller's package allows :limit.", [
                        'total' => $total,
                        'item' => self::label($key),
                        'limit' => $limit,
                    ]),
                ]);
            }
        }
    }

    /**
     * Refuse a package of the reseller that gives out more than the
     * reseller's own limits, or SSL that the reseller may not give.
     *
     * @param  array<string, mixed>  $data  The package's validated limits and ssl.
     *
     * @throws ValidationException
     */
    public function ensurePackageFits(array $data): void
    {
        $errors = [];

        foreach (Package::LIMITS as $key) {
            $limit = $this->limit($key);
            $given = $data[$key] ?? null;

            if ($limit !== null && ($given === null || (int) $given > $limit)) {
                $errors[$key] = __('At most :limit, the limit of your reseller package.', ['limit' => $limit]);
            }
        }

        if (($data['ssl'] ?? false) && ! $this->allowsSsl()) {
            $errors['ssl'] = __('Your reseller package does not include SSL.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Refuse new limits for a package of the reseller that do not fit its
     * reseller package, or that would make the accounts on it, together
     * with the reseller's other accounts, get more than it may give out.
     *
     * @param  array<string, mixed>  $data  The package's validated limits and ssl.
     *
     * @throws ValidationException
     */
    public function ensurePackageChangeFits(Package $package, array $data): void
    {
        $this->ensurePackageFits($data);

        $onPackage = $this->accounts()->where('package_id', $package->id);

        if ($this->oversells() || $onPackage->isEmpty()) {
            return;
        }

        $errors = [];

        foreach (Package::LIMITS as $key) {
            $limit = $this->limit($key);

            if ($limit === null) {
                continue;
            }

            $others = $this->accounts()
                ->where('package_id', '!=', $package->id)
                ->sum(fn (User $account): int => $account->package?->limit($key) ?? $limit + 1);
            $total = $others + $onPackage->count() * (int) $data[$key];

            if ($total > $limit) {
                $errors[$key] = __('With the :count account(s) on this package, the reseller would give out :total, more than the :limit of its reseller package.', [
                    'count' => $onPackage->count(),
                    'total' => $total,
                    'limit' => $limit,
                ]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The quantity's name for messages, e.g. "MB of disk space".
     */
    public static function label(string $key): string
    {
        return match ($key) {
            'accounts' => __('accounts'),
            'disk_mb' => __('MB of disk space'),
            'inodes' => __('files (inodes)'),
            'bandwidth_mb' => __('MB of bandwidth'),
            'domains' => __('domains'),
            'subdomains' => __('subdomains'),
            'pointers' => __('domain pointers'),
            'email_accounts' => __('email accounts'),
            'email_forwarders' => __('email forwarders'),
            'databases' => __('databases'),
            'ftp_accounts' => __('FTP accounts'),
            'cron_jobs' => __('cron jobs'),
            default => $key,
        };
    }

    /**
     * The limits, usage and allocation for the pages.
     *
     * @return array{package: string|null, oversell: bool, ssl: bool, quotas: array<string, array{limit: int|null, used: int|null, allocated: int|null}>}
     */
    public function toArray(): array
    {
        $quotas = [];

        foreach (ResellerPackage::LIMITS as $key) {
            $quotas[$key] = [
                'limit' => $this->limit($key),
                'used' => $this->usage($key),
                'allocated' => $this->allocated($key),
            ];
        }

        return [
            'package' => $this->reseller->resellerPackage?->name,
            'oversell' => $this->oversells(),
            'ssl' => $this->allowsSsl(),
            'quotas' => $quotas,
        ];
    }

    /**
     * The reseller's hosting accounts that are not being deleted, with
     * their packages.
     *
     * @return Collection<int, User>
     */
    private function accounts(): Collection
    {
        return $this->accounts ??= User::query()
            ->with('package')
            ->where('owner_id', $this->reseller->id)
            ->where('role', Role::User)
            ->where('status', '!=', AccountStatus::Deleting)
            ->get();
    }

    /**
     * The ids of the reseller's hosting accounts, as a subquery.
     */
    private function accountIds(): QueryBuilder
    {
        return User::query()
            ->select('id')
            ->where('owner_id', $this->reseller->id)
            ->where('role', Role::User)
            ->where('status', '!=', AccountStatus::Deleting)
            ->toBase();
    }
}
