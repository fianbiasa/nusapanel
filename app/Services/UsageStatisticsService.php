<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AccountUsage;
use App\Models\BandwidthUsage;
use App\Models\UsageSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Usage over time and rankings for the statistics pages and the
 * dashboard: daily bandwidth (from the agent's counts) and disk space
 * (from the daily snapshots) of one account or a group of accounts.
 */
class UsageStatisticsService
{
    /**
     * The hosting accounts an administrator (every one) or a reseller (its
     * own) sees, as a subquery of ids.
     */
    public function accountsOf(User $viewer): Builder
    {
        return User::query()
            ->select('id')
            ->where('role', Role::User)
            ->when($viewer->isReseller(), fn ($query) => $query->where('owner_id', $viewer->id))
            ->toBase();
    }

    /**
     * Bandwidth and disk space per day for the last days, oldest first,
     * summed over the accounts. A day without a disk snapshot has none.
     *
     * @param  Builder|list<int>  $accounts  Account ids, or a subquery of them.
     * @return list<array{date: string, bandwidth_bytes: int, disk_kb: int|null}>
     */
    public function history(Builder|array $accounts, int $days = 30): array
    {
        $end = CarbonImmutable::today();
        $start = $end->subDays($days - 1);
        $range = [$start->toDateString(), $end->toDateString()];

        $bandwidth = BandwidthUsage::query()
            ->toBase()
            ->whereIn('user_id', $accounts)
            ->whereBetween('date', $range)
            ->groupBy('date')
            ->selectRaw('date, sum(web_bytes + mail_bytes + ftp_bytes) as total')
            ->pluck('total', 'date');

        $disk = UsageSnapshot::query()
            ->toBase()
            ->whereIn('user_id', $accounts)
            ->whereBetween('date', $range)
            ->groupBy('date')
            ->selectRaw('date, sum(disk_kb) as total')
            ->pluck('total', 'date');

        $history = [];

        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $date = $day->toDateString();

            $history[] = [
                'date' => $date,
                'bandwidth_bytes' => (int) ($bandwidth[$date] ?? 0),
                'disk_kb' => isset($disk[$date]) ? (int) $disk[$date] : null,
            ];
        }

        return $history;
    }

    /**
     * The accounts that moved the most this month, most first.
     *
     * @return list<array{id: int, username: string, bytes: int, limit_mb: int|null}>
     */
    public function topBandwidth(Builder $accounts, int $limit = 20): array
    {
        $totals = BandwidthUsage::query()
            ->toBase()
            ->whereIn('user_id', $accounts)
            ->where('date', '>=', CarbonImmutable::now()->startOfMonth()->toDateString())
            ->groupBy('user_id')
            ->selectRaw('user_id, sum(web_bytes + mail_bytes + ftp_bytes) as total')
            ->orderByDesc('total')
            ->limit($limit)
            ->pluck('total', 'user_id');

        $users = User::query()->with('package:id,bandwidth_mb')->whereKey($totals->keys())->get(['id', 'username', 'package_id'])->keyBy('id');

        return array_values($totals
            ->map(fn (mixed $total, int $id): ?array => $users->has($id) ? [
                'id' => $id,
                'username' => $users[$id]->username,
                'bytes' => (int) $total,
                'limit_mb' => $users[$id]->package?->limit('bandwidth_mb'),
            ] : null)
            ->filter()
            ->all());
    }

    /**
     * The accounts that use the most disk space, as last measured, most first.
     *
     * @return list<array{id: int, username: string, disk_kb: int, limit_mb: int|null}>
     */
    public function topDisk(Builder $accounts, int $limit = 20): array
    {
        return array_values(AccountUsage::query()
            ->with('account:id,username,package_id', 'account.package:id,disk_mb')
            ->whereIn('user_id', $accounts)
            ->orderByDesc('disk_kb')
            ->limit($limit)
            ->get()
            ->map(fn (AccountUsage $usage): array => [
                'id' => $usage->user_id,
                'username' => $usage->account->username,
                'disk_kb' => $usage->disk_kb,
                'limit_mb' => $usage->account->package?->limit('disk_mb'),
            ])
            ->all());
    }

    /**
     * This month's bandwidth and the disk space last measured, of all the
     * accounts together.
     *
     * @return array{bandwidth_bytes: int, disk_kb: int, accounts: int}
     */
    public function totals(Builder $accounts): array
    {
        return [
            'bandwidth_bytes' => (int) BandwidthUsage::query()
                ->whereIn('user_id', $accounts)
                ->where('date', '>=', CarbonImmutable::now()->startOfMonth()->toDateString())
                ->sum(DB::raw('web_bytes + mail_bytes + ftp_bytes')),
            'disk_kb' => (int) AccountUsage::query()->whereIn('user_id', $accounts)->sum('disk_kb'),
            'accounts' => DB::query()->fromSub($accounts, 'accounts')->count(),
        ];
    }
}
