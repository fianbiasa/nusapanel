<?php

namespace App\Services;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The server's processes, as the agent measures them over half a second:
 * CPU in percent of one CPU, memory as resident bytes. Administrators see
 * and stop every process; a hosting account sees what its own processes
 * use together.
 */
class ProcessService
{
    public const SIGNALS = ['TERM', 'KILL'];

    private const TIMEOUT = 30;

    public function __construct(
        private AgentClient $agent,
        private AuditLogger $audit,
    ) {}

    /**
     * Every process, busiest first, and what each user uses together.
     *
     * @return array<string, mixed>
     *
     * @throws AgentException
     */
    public function list(): array
    {
        return $this->agent->call('process.list', [], self::TIMEOUT);
    }

    /**
     * What the account's processes use together right now, or null when the
     * agent could not tell.
     *
     * @return array{processes: int, cpu: float, memory: int, cpus: int, memory_total: int}|null
     */
    public function accountUsage(User $account): ?array
    {
        try {
            $snapshot = $this->agent->call('process.list', ['username' => $account->username], self::TIMEOUT);
        } catch (AgentException $exception) {
            report($exception);

            return null;
        }

        /** @var array{processes?: int, cpu?: float|int, memory?: int} $usage */
        $usage = $snapshot['users'][0] ?? [];

        return [
            'processes' => (int) ($usage['processes'] ?? 0),
            'cpu' => (float) ($usage['cpu'] ?? 0),
            'memory' => (int) ($usage['memory'] ?? 0),
            'cpus' => (int) ($snapshot['cpus'] ?? 1),
            'memory_total' => (int) ($snapshot['memory_total'] ?? 0),
        ];
    }

    /**
     * Stop a process, if it is still the one the list showed.
     *
     * @param  array{pid: int, start: string, signal: string, user: string, command: string}  $process
     *
     * @throws ValidationException
     */
    public function kill(array $process, User $actor): void
    {
        try {
            $this->agent->call('process.kill', [
                'pid' => $process['pid'],
                'start' => $process['start'],
                'signal' => $process['signal'],
            ], self::TIMEOUT);
        } catch (AgentException $exception) {
            if ($exception->reason === null) {
                report($exception);
            }

            throw ValidationException::withMessages(['process' => match ($exception->reason) {
                'gone' => __('Process :pid has ended already.', ['pid' => $process['pid']]),
                'protected' => __('Process :pid may not be stopped.', ['pid' => $process['pid']]),
                default => __('Process :pid could not be stopped. Try again in a moment.', ['pid' => $process['pid']]),
            }]);
        }

        $this->audit->record('server.process_killed', null, [
            'pid' => $process['pid'],
            'signal' => $process['signal'],
            'user' => $process['user'],
            'command' => mb_substr($process['command'], 0, 200),
        ], $actor);
    }
}
