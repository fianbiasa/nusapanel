<?php

namespace App\Services;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\Role;
use App\Models\ResourceCounter;
use App\Models\ResourceUsage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * How much CPU time and memory hosting accounts use, day by day, from
 * their cgroups: their PHP pools and SSH sessions (not their cron jobs).
 * The agent reports a CPU counter per cgroup; what each grew by since the
 * last reading is today's. Memory is sampled at each reading, and the day
 * keeps the most.
 */
class ResourceUsageService
{
    public function __construct(private AgentClient $agent) {}

    /**
     * Read every account's use and add it to today's.
     *
     * @return int The number of accounts measured.
     *
     * @throws AgentException When the agent is unreachable or reports an error; nothing is stored then.
     */
    public function collect(): int
    {
        $report = $this->agent->call('usage.resources', [], (int) config('agent.timeout'));
        $usages = $this->reported($report['accounts'] ?? null);

        if ($usages === []) {
            return 0;
        }

        $accounts = User::query()
            ->where('role', Role::User)
            ->whereIn('username', array_keys($usages))
            ->pluck('id', 'username');

        $today = now()->toDateString();

        DB::transaction(function () use ($accounts, $usages, $today): void {
            $counters = ResourceCounter::query()->whereIn('user_id', $accounts->values())->get()->groupBy('user_id');
            $measuredBefore = ResourceUsage::query()->whereIn('user_id', $accounts->values())->distinct()->pluck('user_id')->flip();

            foreach ($accounts as $username => $id) {
                $usage = $usages[$username];
                $previous = [];

                foreach ($counters->get($id, []) as $counter) {
                    $previous[$counter->cgroup_id] = $counter->cpu_usec;
                }

                $usedUsec = 0;

                foreach ($usage['cpu'] as $cgroupId => $usec) {
                    // A cgroup not seen before started since the last
                    // reading, unless the account was never read: then
                    // its counter holds what came before and is the start.
                    $usedUsec += match (true) {
                        isset($previous[$cgroupId]) => max(0, $usec - $previous[$cgroupId]),
                        $measuredBefore->has($id) => $usec,
                        default => 0,
                    };
                }

                ResourceCounter::query()->where('user_id', $id)->delete();
                ResourceCounter::query()->insert(array_map(
                    fn (int $cgroupId, int $usec): array => ['user_id' => $id, 'cgroup_id' => $cgroupId, 'cpu_usec' => $usec],
                    array_keys($usage['cpu']),
                    $usage['cpu'],
                ));

                $day = ResourceUsage::query()->firstOrNew(['user_id' => $id, 'date' => $today]);
                $day->forceFill([
                    'cpu_ms' => ($day->cpu_ms ?? 0) + intdiv($usedUsec, 1000),
                    'memory_peak_mb' => max($day->memory_peak_mb ?? 0, (int) ceil($usage['memory_bytes'] / 1048576)),
                ])->save();
            }
        });

        return $accounts->count();
    }

    /**
     * The well-formed readings in the agent's report, by username: the
     * memory, and the CPU counters by cgroup id.
     *
     * @return array<string, array{memory_bytes: int, cpu: array<int, int>}>
     */
    private function reported(mixed $accounts): array
    {
        if (! is_array($accounts)) {
            return [];
        }

        $usages = [];

        foreach ($accounts as $account) {
            if (! is_array($account) || ! is_string($account['username'] ?? null) || ! is_array($account['cpu'] ?? null)) {
                continue;
            }

            $memory = $this->count($account['memory_bytes'] ?? null);
            $cpu = [];

            foreach ($account['cpu'] as $cgroup) {
                $cgroupId = $this->count(is_array($cgroup) ? $cgroup['id'] ?? null : null);
                $usec = $this->count(is_array($cgroup) ? $cgroup['usec'] ?? null : null);

                if ($cgroupId !== null && $usec !== null) {
                    $cpu[$cgroupId] = $usec;
                }
            }

            if ($memory !== null) {
                $usages[$account['username']] = ['memory_bytes' => $memory, 'cpu' => $cpu];
            }
        }

        return $usages;
    }

    /**
     * A whole number that is not negative, or null.
     */
    private function count(mixed $value): ?int
    {
        $count = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $count === false ? null : $count;
    }
}
