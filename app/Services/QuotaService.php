<?php

namespace App\Services;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Models\AccountUsage;
use App\Models\Package;
use App\Models\UsageSnapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Disk quotas of hosting accounts. The server limits each account's disk
 * space and number of files (inodes) to what its package allows, and
 * reports how much of both every account uses.
 *
 * Only hosting accounts that exist on the server (active or suspended) get
 * a quota: an account that is still being created gets one once it exists,
 * and deleting an account removes its quota on the server.
 */
class QuotaService
{
    /**
     * Remembers whether the server enforces disk quotas, as last reported.
     */
    public const ENABLED_CACHE_KEY = 'hosting.quota_enabled';

    public function __construct(
        private TaskDispatcher $tasks,
        private AgentClient $agent,
    ) {}

    /**
     * Queue the account's quota on the server: its package's disk and inode
     * limits, where 0 means unlimited. Accounts that do not exist on the
     * server are skipped.
     *
     * @return bool Whether the quota was queued.
     */
    public function apply(User $account, ?User $actor = null): bool
    {
        if (! $account->hasRole(Role::User) || ! in_array($account->status, [AccountStatus::Active, AccountStatus::Suspended], true)) {
            return false;
        }

        $this->tasks->dispatch('account.set_quota', [
            'username' => $account->username,
            'disk_mb' => $account->package->disk_mb ?? 0,
            'inodes' => $account->package->inodes ?? 0,
        ], $account, $actor);

        return true;
    }

    /**
     * Queue the quota of an account the agent has just created. A new Linux
     * user has no quota, so only a package that limits disk space or inodes
     * needs one.
     */
    public function applyToNewAccount(User $account, ?User $actor): void
    {
        $package = $account->package;

        if ($package === null || ($package->disk_mb === null && $package->inodes === null)) {
            return;
        }

        $this->apply($account, $actor);
    }

    /**
     * Queue the quota of every account on the package again, e.g. after its
     * disk or inode limit changed.
     *
     * @return int The number of accounts queued.
     */
    public function applyToPackage(Package $package, ?User $actor): int
    {
        return $this->applyToEach($package->accounts()->getQuery(), $actor);
    }

    /**
     * Queue the quota of every hosting account again, e.g. after an upgrade
     * or once quotas were enabled on the server.
     *
     * @return int The number of accounts queued.
     */
    public function applyAll(?User $actor = null): int
    {
        return $this->applyToEach(User::query(), $actor);
    }

    /**
     * Ask the server how much disk space and how many inodes every hosting
     * account uses, store it, and remember whether the server enforces
     * quotas. Accounts the server does not report keep their last
     * measurement; names it reports that are not hosting accounts are
     * ignored.
     *
     * @return int The number of accounts measured.
     *
     * @throws AgentException When the agent is unreachable or reports an error; nothing is stored then.
     */
    public function collectUsage(): int
    {
        $report = $this->agent->call('quota.report', [], (int) config('agent.timeout'));

        $usage = $this->reportedUsage($report['accounts'] ?? null);
        $measuredAt = now();
        $rows = [];

        foreach (array_chunk(array_keys($usage), 500) as $usernames) {
            $accountIds = User::query()
                ->where('role', Role::User)
                ->whereIn('username', $usernames)
                ->pluck('id', 'username');

            foreach ($accountIds as $username => $id) {
                $rows[] = [
                    'user_id' => $id,
                    'disk_kb' => $usage[$username]['disk_kb'],
                    'inodes' => $usage[$username]['inodes'],
                    'measured_at' => $measuredAt,
                ];
            }
        }

        $today = $measuredAt->toDateString();

        DB::transaction(function () use ($rows, $today): void {
            foreach (array_chunk($rows, 500) as $chunk) {
                AccountUsage::query()->upsert($chunk, ['user_id'], ['disk_kb', 'inodes', 'measured_at']);

                // Today's snapshot for the usage graphs, the day's last measurement.
                UsageSnapshot::query()->upsert(array_map(fn (array $row): array => [
                    'user_id' => $row['user_id'],
                    'date' => $today,
                    'disk_kb' => $row['disk_kb'],
                    'inodes' => $row['inodes'],
                ], $chunk), ['user_id', 'date'], ['disk_kb', 'inodes']);
            }
        });

        Cache::forever(self::ENABLED_CACHE_KEY, ($report['enabled'] ?? false) === true);

        return count($rows);
    }

    /**
     * Whether the server enforces disk quotas, or null until it was asked.
     */
    public static function isEnabled(): ?bool
    {
        $enabled = Cache::get(self::ENABLED_CACHE_KEY);

        return is_bool($enabled) ? $enabled : null;
    }

    /**
     * Queue the quota of every hosting account in the query that exists on
     * the server.
     *
     * @param  Builder<User>  $query
     */
    private function applyToEach(Builder $query, ?User $actor): int
    {
        $queued = 0;

        $query->with('package')
            ->where('role', Role::User)
            ->whereIn('status', [AccountStatus::Active, AccountStatus::Suspended])
            ->orderBy('id')
            ->each(function (User $account) use ($actor, &$queued): void {
                if ($this->apply($account, $actor)) {
                    $queued++;
                }
            });

        return $queued;
    }

    /**
     * The usage of every well-formed account in the agent's report, by username.
     *
     * @return array<string, array{disk_kb: int, inodes: int}>
     */
    private function reportedUsage(mixed $accounts): array
    {
        if (! is_array($accounts)) {
            return [];
        }

        $usage = [];
        $count = ['options' => ['min_range' => 0]];

        foreach ($accounts as $account) {
            if (! is_array($account) || ! is_string($account['username'] ?? null)) {
                continue;
            }

            $diskKb = filter_var($account['disk_kb'] ?? null, FILTER_VALIDATE_INT, $count);
            $inodes = filter_var($account['inodes'] ?? null, FILTER_VALIDATE_INT, $count);

            if ($diskKb === false || $inodes === false) {
                continue;
            }

            $usage[$account['username']] = ['disk_kb' => $diskKb, 'inodes' => $inodes];
        }

        return $usage;
    }
}
