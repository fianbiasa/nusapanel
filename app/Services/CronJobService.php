<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\CronJob;
use App\Models\Task;
use App\Models\User;
use App\Support\AccountLimits;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds, changes and removes the cron jobs of hosting accounts. Every change
 * sends the account's whole set of enabled jobs to the agent (cron.apply),
 * which makes it the account's crontab, so applying it again is always safe
 * and the last change wins.
 *
 * A suspended account keeps its crontab, but cron does not run it: the
 * account is expired while suspended, and cron checks accounts through PAM.
 */
class CronJobService
{
    public function __construct(
        private TaskDispatcher $tasks,
        private AuditLogger $audit,
    ) {}

    /**
     * Add an enabled job to the account, within its package's limit.
     *
     * @throws ValidationException
     */
    public function create(User $account, string $schedule, string $command, ?string $description, User $actor): CronJob
    {
        $this->ensureAccountIsActive($account);

        return DB::transaction(function () use ($account, $schedule, $command, $description, $actor): CronJob {
            $this->ensureLimitIsNotReached($account, $actor);

            $job = $account->cronJobs()->create([
                'schedule' => $schedule,
                'command' => $command,
                'description' => $description,
                'enabled' => true,
            ]);

            $this->apply($account, $actor);

            $this->audit->record('cron_job.created', $job, ['schedule' => $schedule, 'command' => $command], $actor);

            return $job;
        });
    }

    /**
     * Change a job, or pause and resume it.
     *
     * @throws ValidationException
     */
    public function update(CronJob $job, string $schedule, string $command, ?string $description, bool $enabled, User $actor): void
    {
        $account = $job->account;

        $this->ensureAccountIsActive($account);

        DB::transaction(function () use ($job, $account, $schedule, $command, $description, $enabled, $actor): void {
            $job->update([
                'schedule' => $schedule,
                'command' => $command,
                'description' => $description,
                'enabled' => $enabled,
            ]);

            $this->apply($account, $actor);

            $this->audit->record('cron_job.updated', $job, [
                'schedule' => $schedule,
                'command' => $command,
                'enabled' => $enabled,
            ], $actor);
        });
    }

    /**
     * Remove a job.
     */
    public function delete(CronJob $job, User $actor): void
    {
        $account = $job->account;

        DB::transaction(function () use ($job, $account, $actor): void {
            $this->audit->record('cron_job.deleted', $job, ['schedule' => $job->schedule, 'command' => $job->command], $actor);

            $job->delete();

            $this->apply($account, $actor);
        });
    }

    /**
     * Where the account's latest crontab change stands: whether the agent is
     * still applying it, and the error when it failed.
     *
     * @return array{pending: bool, error: string|null}
     */
    public function syncState(User $account): array
    {
        $task = Task::query()
            ->where('user_id', $account->id)
            ->where('action', 'cron.apply')
            ->latest('created_at')
            ->latest('id')
            ->first();

        return [
            'pending' => in_array($task?->status, [TaskStatus::Queued, TaskStatus::Running], true),
            'error' => $task?->status === TaskStatus::Failed ? ($task->error ?? '') : null,
        ];
    }

    /**
     * Queue the crontab of every active or suspended hosting account again,
     * from its jobs in the panel (an account without jobs gets none). Used
     * by rayxo:rebuild.
     *
     * @return int The number of accounts queued.
     */
    public function reapplyAll(?User $actor = null): int
    {
        $queued = 0;

        User::query()
            ->where('role', Role::User)
            ->whereIn('status', [AccountStatus::Active, AccountStatus::Suspended])
            ->orderBy('id')
            ->each(function (User $account) use ($actor, &$queued): void {
                $this->apply($account, $actor);
                $queued++;
            });

        return $queued;
    }

    /**
     * Queue the account's enabled jobs as its crontab.
     */
    private function apply(User $account, ?User $actor): void
    {
        $jobs = $account->cronJobs()
            ->where('enabled', true)
            ->orderBy('id')
            ->get()
            ->map(fn (CronJob $job): array => ['schedule' => $job->schedule, 'command' => $job->command])
            ->all();

        $this->tasks->dispatch('cron.apply', [
            'username' => $account->username,
            'jobs' => $jobs,
        ], $account, $actor);
    }

    /**
     * @throws ValidationException
     */
    private function ensureAccountIsActive(User $account): void
    {
        if (! $account->hasRole(Role::User) || $account->status !== AccountStatus::Active) {
            throw ValidationException::withMessages([
                'schedule' => __('Cron jobs can only be changed while the account is active.'),
            ]);
        }
    }

    /**
     * Refuse a job the account's package has no room for. The account's row
     * stays locked until the job is saved, so two requests cannot both take
     * the last free place.
     *
     * @throws ValidationException
     */
    private function ensureLimitIsNotReached(User $account, User $actor): void
    {
        $locked = User::query()->with('package')->lockForUpdate()->findOrFail($account->id);
        $limits = AccountLimits::for($locked);

        if (! $limits->isReached('cron_jobs')) {
            return;
        }

        $replace = ['limit' => $limits->limit('cron_jobs')];

        throw ValidationException::withMessages([
            'schedule' => $actor->is($account)
                ? __('Your package allows :limit cron jobs. Remove one or ask your provider for a bigger package.', $replace)
                : __("This account's package allows :limit cron jobs.", $replace),
        ]);
    }
}
