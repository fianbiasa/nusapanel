<?php

namespace App\Jobs;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\TaskStatus;
use App\Events\AgentTaskFinished;
use App\Models\Task;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class RunAgentTask implements ShouldQueue
{
    use Queueable;

    /**
     * Unlimited tries so the per-account lock can release the job while
     * another task for the same account is running; real errors are
     * capped by $maxExceptions and agent errors never retry.
     */
    public int $tries = 0;

    public int $maxExceptions = 1;

    public int $timeout = 600;

    /**
     * Tasks that may run for hours (a backup or restore of a large
     * account), with how many seconds they may take; the agent bounds them
     * the same way.
     *
     * @var array<string, int>
     */
    public const LONG_TASKS = [
        'backup.create' => 6 * 3600,
        'backup.restore' => 6 * 3600,
    ];

    public function __construct(public Task $task)
    {
        if (isset(self::LONG_TASKS[$task->action])) {
            $this->timeout = self::LONG_TASKS[$task->action] + 300;
        }
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }

    /**
     * Only one task may change the same account at a time.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('account:'.($this->task->user_id ?? 'system')))
                ->releaseAfter(5)
                ->expireAfter($this->timeout),
        ];
    }

    public function handle(AgentClient $agent): void
    {
        $this->task->refresh();

        if ($this->task->status !== TaskStatus::Queued) {
            return;
        }

        $this->task->markRunning();

        try {
            $output = $agent->call(
                $this->task->action,
                $this->task->payload ?? [],
                self::LONG_TASKS[$this->task->action] ?? (int) config('agent.task_timeout'),
            );
        } catch (AgentException $exception) {
            $this->task->markFailed($exception->getMessage());

            AgentTaskFinished::dispatch($this->task);

            return;
        }

        $this->task->markSucceeded($output);

        AgentTaskFinished::dispatch($this->task);
    }

    public function failed(?Throwable $exception): void
    {
        $this->task->markFailed($exception?->getMessage() ?? 'The task failed unexpectedly.');

        AgentTaskFinished::dispatch($this->task);
    }
}
