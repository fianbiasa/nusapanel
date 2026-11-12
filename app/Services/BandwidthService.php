<?php

namespace App\Services;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Models\BandwidthUsage;
use App\Models\Domain;
use App\Models\DomainBandwidth;
use App\Models\FtpAccount;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Bandwidth: what hosting accounts and their websites move, counted by the
 * agent from the logs of Nginx, Postfix and Pure-FTPd (usage.bandwidth).
 * Each count starts where the previous one stopped; the cursor is kept
 * with the counts it led to, in one transaction, so nothing is counted
 * twice or lost. Package limits are per calendar month.
 */
class BandwidthService
{
    /**
     * The setting that holds where the last count stopped in each log.
     */
    public const CURSOR_SETTING = 'bandwidth.cursor';

    /**
     * The setting that turns suspending accounts over their bandwidth on.
     */
    public const SUSPEND_SETTING = 'bandwidth.suspend_over_limit';

    /**
     * How many counts one collection makes at most, while the agent says
     * there is more log to read.
     */
    private const MAX_COUNTS = 10;

    private const BYTES_PER_MB = 1048576;

    public function __construct(
        private AgentClient $agent,
        private AccountService $accounts,
    ) {}

    /**
     * Count the traffic since the last count and add it up per hosting
     * account and website per day. Traffic of domains and FTP logins the
     * panel does not know is left out.
     *
     * @return int The bytes added.
     *
     * @throws AgentException
     */
    public function collect(): int
    {
        $added = 0;

        for ($count = 0; $count < self::MAX_COUNTS; $count++) {
            $cursor = Setting::query()->where('key', self::CURSOR_SETTING)->value('value');

            $report = $this->agent->call('usage.bandwidth', [
                'cursor' => is_array($cursor) && $cursor !== [] ? $cursor : (object) [],
            ], (int) config('agent.timeout'));

            $added += $this->store($report);

            if (($report['complete'] ?? true) !== false) {
                break;
            }
        }

        return $added;
    }

    /**
     * The bytes the account moved in the month of the given day (this
     * month when none is given).
     */
    public static function monthBytes(User $account, ?CarbonImmutable $day = null): int
    {
        $day ??= CarbonImmutable::now();

        return (int) BandwidthUsage::query()
            ->where('user_id', $account->id)
            ->whereBetween('date', [$day->startOfMonth()->toDateString(), $day->endOfMonth()->toDateString()])
            ->sum(DB::raw('web_bytes + mail_bytes + ftp_bytes'));
    }

    /**
     * Whole megabytes, rounded up.
     */
    public static function megabytes(int $bytes): int
    {
        return (int) ceil($bytes / self::BYTES_PER_MB);
    }

    public function suspendsOverLimit(): bool
    {
        return Setting::query()->where('key', self::SUSPEND_SETTING)->value('value') === true;
    }

    /**
     * Turn suspending accounts that go over their package's monthly
     * bandwidth on or off.
     */
    public function setSuspendOverLimit(bool $enabled, User $actor, AuditLogger $audit): void
    {
        DB::transaction(function () use ($enabled, $actor, $audit): void {
            Setting::query()->updateOrCreate(['key' => self::SUSPEND_SETTING], ['value' => $enabled]);

            $audit->record('server.bandwidth_suspend_changed', null, ['enabled' => $enabled], $actor);
        });
    }

    /**
     * Lift the suspensions of an earlier month once a new month starts,
     * and, when it is turned on, suspend the active hosting accounts that
     * went over their package's bandwidth this month.
     *
     * @return array{suspended: int, unsuspended: int}
     */
    public function enforceLimits(): array
    {
        $monthStart = CarbonImmutable::now()->startOfMonth();
        $unsuspended = 0;
        $suspended = 0;

        User::query()
            ->where('role', Role::User)
            ->whereNotNull('bandwidth_suspended_at')
            ->where('bandwidth_suspended_at', '<', $monthStart)
            ->each(function (User $account) use (&$unsuspended): void {
                if ($account->status === AccountStatus::Suspended) {
                    $this->accounts->unsuspend($account, null);
                    $unsuspended++;
                } else {
                    $account->forceFill(['bandwidth_suspended_at' => null])->save();
                }
            });

        if (! $this->suspendsOverLimit()) {
            return ['suspended' => 0, 'unsuspended' => $unsuspended];
        }

        $totals = BandwidthUsage::query()
            ->where('date', '>=', $monthStart->toDateString())
            ->groupBy('user_id')
            ->selectRaw('user_id, sum(web_bytes + mail_bytes + ftp_bytes) as total')
            ->pluck('total', 'user_id');

        User::query()
            ->with('package')
            ->where('role', Role::User)
            ->where('status', AccountStatus::Active)
            ->whereIn('id', $totals->keys())
            ->each(function (User $account) use ($totals, &$suspended): void {
                $limit = $account->package?->limit('bandwidth_mb');

                if ($limit === null || (int) $totals[$account->id] <= $limit * self::BYTES_PER_MB) {
                    return;
                }

                DB::transaction(function () use ($account): void {
                    $this->accounts->suspend($account, __('The bandwidth of the package for this month is used up.'), null);
                    $account->forceFill(['bandwidth_suspended_at' => now()])->save();
                });

                $suspended++;
            });

        return ['suspended' => $suspended, 'unsuspended' => $unsuspended];
    }

    /**
     * Add one count of the agent to the daily totals and keep its cursor.
     *
     * @param  array<string, mixed>  $report
     * @return int The bytes added.
     */
    private function store(array $report): int
    {
        $web = $this->entries($report['web'] ?? null, 'domain', lowercase: true);
        $mail = $this->entries($report['mail'] ?? null, 'domain', lowercase: true);
        $ftp = $this->entries($report['ftp'] ?? null, 'user', lowercase: false);

        $domains = Domain::query()
            ->whereIn('name', array_unique([...array_column($web, 'name'), ...array_column($mail, 'name')]))
            ->get(['id', 'user_id', 'name'])
            ->keyBy('name');
        $logins = FtpAccount::query()
            ->whereIn('login', array_unique(array_column($ftp, 'name')))
            ->pluck('user_id', 'login');

        /** @var array<int, array<string, array{web_bytes: int, mail_bytes: int, ftp_bytes: int}>> $accounts */
        $accounts = [];
        /** @var array<int, array<string, int>> $websites */
        $websites = [];
        $added = 0;

        foreach (['web_bytes' => $web, 'mail_bytes' => $mail] as $column => $entries) {
            foreach ($entries as $entry) {
                $domain = $domains->get($entry['name']);

                if ($domain === null) {
                    continue;
                }

                $accounts[$domain->user_id][$entry['date']] ??= ['web_bytes' => 0, 'mail_bytes' => 0, 'ftp_bytes' => 0];
                $accounts[$domain->user_id][$entry['date']][$column] += $entry['bytes'];
                $websites[$domain->id][$entry['date']] = ($websites[$domain->id][$entry['date']] ?? 0) + $entry['bytes'];
                $added += $entry['bytes'];
            }
        }

        foreach ($ftp as $entry) {
            $userId = $logins->get($entry['name']);

            if ($userId === null) {
                continue;
            }

            $accounts[$userId][$entry['date']] ??= ['web_bytes' => 0, 'mail_bytes' => 0, 'ftp_bytes' => 0];
            $accounts[$userId][$entry['date']]['ftp_bytes'] += $entry['bytes'];
            $added += $entry['bytes'];
        }

        DB::transaction(function () use ($accounts, $websites, $report): void {
            foreach ($accounts as $userId => $days) {
                foreach ($days as $date => $bytes) {
                    $usage = BandwidthUsage::query()->firstOrNew(['user_id' => $userId, 'date' => $date]);

                    foreach ($bytes as $column => $value) {
                        $usage->{$column} = (int) $usage->{$column} + $value;
                    }

                    $usage->save();
                }
            }

            foreach ($websites as $domainId => $days) {
                foreach ($days as $date => $bytes) {
                    $usage = DomainBandwidth::query()->firstOrNew(['domain_id' => $domainId, 'date' => $date]);
                    $usage->bytes = (int) $usage->bytes + $bytes;
                    $usage->save();
                }
            }

            Setting::query()->updateOrCreate(
                ['key' => self::CURSOR_SETTING],
                ['value' => is_array($report['cursor'] ?? null) ? $report['cursor'] : []],
            );
        });

        return $added;
    }

    /**
     * The well-formed entries of one kind of the agent's count.
     *
     * @return list<array{name: string, date: string, bytes: int}>
     */
    private function entries(mixed $entries, string $nameKey, bool $lowercase): array
    {
        if (! is_array($entries)) {
            return [];
        }

        $valid = [];

        foreach ($entries as $entry) {
            $name = $entry[$nameKey] ?? null;
            $date = $entry['date'] ?? null;
            $bytes = $entry['bytes'] ?? null;

            if (! is_string($name) || ! is_string($date) || ! is_int($bytes) || $bytes <= 0
                || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
                continue;
            }

            $valid[] = ['name' => $lowercase ? strtolower($name) : $name, 'date' => $date, 'bytes' => $bytes];
        }

        return $valid;
    }
}
