<?php

namespace App\Http\Controllers\Admin;

use App\Agent\AgentException;
use App\Enums\ServerBackupStatus;
use App\Http\Controllers\Controller;
use App\Models\BackupRun;
use App\Models\ServerBackup;
use App\Services\AuditLogger;
use App\Services\ServerBackupService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The automatic backups of every account: whether and when they run, how
 * many are kept, the disk they need, and the backups the server keeps.
 */
class ServerBackupController extends Controller
{
    public function __construct(private ServerBackupService $backups, private AuditLogger $audit) {}

    /**
     * The schedule, the disk, the latest runs and the backups, and with
     * ?restore=<id> what that backup holds.
     */
    public function index(Request $request): Response
    {
        $schedule = $this->backups->schedule();
        $timezone = $this->backups->timezone();
        $accounts = $this->backups->accounts();
        $search = $request->string('search')->trim()->toString();

        try {
            $space = $this->backups->space();
        } catch (AgentException $exception) {
            report($exception);
            $space = null;
        }

        $backups = ServerBackup::query()
            ->with('run:id,trigger')
            ->withExists('account')
            ->when($search !== '', fn ($query) => $query->where('username', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ServerBackup $backup): array => [
                'id' => $backup->id,
                'username' => $backup->username,
                'account_exists' => (bool) $backup->getAttribute('account_exists'),
                'name' => $backup->name,
                'status' => $backup->status->value,
                'files' => $backup->files,
                'email' => $backup->email,
                'databases' => count($backup->databases),
                'size' => $backup->size_bytes,
                'skipped' => $backup->skipped,
                'error' => $backup->error,
                'automatic' => $backup->backup_run_id !== null,
                'created_at' => $backup->created_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/backups/index', [
            'schedule' => [
                'enabled' => $schedule['enabled'],
                'frequency' => $schedule['frequency'],
                'hour' => $schedule['hour'],
                'weekday' => $schedule['weekday'],
                'day' => $schedule['day'],
                'keep' => $schedule['keep'],
                'files' => $schedule['files'],
                'email' => $schedule['email'],
                'databases' => $schedule['databases'],
            ],
            'timezone' => $timezone,
            'next_run' => $this->nextRun($schedule, $timezone),
            'space' => $space,
            'estimate' => [
                'accounts' => $accounts->count(),
                'per_run' => $this->backups->estimate($accounts),
                'stored' => (int) ServerBackup::query()->where('status', ServerBackupStatus::Completed)->sum('size_bytes'),
            ],
            'runs' => BackupRun::query()->latest('id')->limit(5)->get()->map(fn (BackupRun $run): array => [
                'id' => $run->id,
                'trigger' => $run->trigger,
                'status' => $run->status->value,
                'accounts' => $run->accounts,
                'succeeded' => $run->succeeded,
                'failed' => $run->failed,
                'message' => $run->message,
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
            ]),
            'running' => $this->backups->running() !== null,
            'backups' => $backups,
            'filters' => ['search' => $search],
            'inspected' => Inertia::optional(fn (): ?array => $this->inspected($request)),
        ]);
    }

    /**
     * Turn the automatic backups on or off, and say when they run and what
     * they hold.
     */
    public function updateSchedule(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'frequency' => ['required', Rule::in(ServerBackupService::FREQUENCIES)],
            'hour' => ['required', 'integer', 'between:0,23'],
            'weekday' => ['required', 'integer', 'between:0,6'],
            'day' => ['required', 'integer', 'between:1,28'],
            'keep' => ['required', 'integer', 'between:1,'.ServerBackupService::MAX_KEEP],
            'files' => ['required', 'boolean'],
            'email' => ['required', 'boolean'],
            'databases' => ['required', 'boolean'],
        ]);

        $this->backups->saveSchedule([
            'enabled' => (bool) $validated['enabled'],
            'frequency' => (string) $validated['frequency'],
            'hour' => (int) $validated['hour'],
            'weekday' => (int) $validated['weekday'],
            'day' => (int) $validated['day'],
            'keep' => (int) $validated['keep'],
            'files' => (bool) $validated['files'],
            'email' => (bool) $validated['email'],
            'databases' => (bool) $validated['databases'],
        ], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => $validated['enabled']
            ? __('Automatic backups are on.')
            : __('Automatic backups are off.')]);

        return back();
    }

    /**
     * Back up every account now.
     */
    public function run(Request $request): RedirectResponse
    {
        $run = $this->backups->start(BackupRun::TRIGGER_MANUAL, $request->user());

        Inertia::flash('toast', $run->message === null
            ? ['type' => 'success', 'message' => __('Backing up :count account(s). This page shows each one as it is done.', ['count' => $run->accounts])]
            : ['type' => 'error', 'message' => $run->message]);

        return back();
    }

    /**
     * Send the backup's file.
     */
    public function download(Request $request, ServerBackup $serverBackup): BinaryFileResponse
    {
        abort_unless($serverBackup->status === ServerBackupStatus::Completed && is_file($serverBackup->path()), 404);

        $this->audit->record('backup.server_downloaded', $serverBackup->account, ['name' => $serverBackup->name, 'username' => $serverBackup->username], $request->user());

        return response()->download($serverBackup->path(), $serverBackup->name, ['Content-Type' => 'application/gzip']);
    }

    /**
     * Restore the parts of a backup asked for into its account.
     */
    public function restore(Request $request, ServerBackup $serverBackup): RedirectResponse
    {
        $validated = $request->validate([
            'files' => ['boolean'],
            'email' => ['boolean'],
            'databases' => ['array', 'max:500'],
            'databases.*' => ['string', 'max:64'],
        ]);

        $this->backups->restore(
            $serverBackup,
            (bool) ($validated['files'] ?? false),
            (bool) ($validated['email'] ?? false),
            array_values(array_unique($validated['databases'] ?? [])),
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Restoring :name into :username. See Tasks for how it goes.', ['name' => $serverBackup->name, 'username' => $serverBackup->username])]);

        return to_route('admin.backups.index');
    }

    /**
     * Delete a backup for good.
     */
    public function destroy(Request $request, ServerBackup $serverBackup): RedirectResponse
    {
        $this->backups->delete($serverBackup, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Backup :name deleted.', ['name' => $serverBackup->name])]);

        return back();
    }

    /**
     * When the next scheduled run starts, in the server's timezone: within
     * the hour when one is due now.
     *
     * @param  array{enabled: bool, frequency: string, hour: int, weekday: int, day: int}  $schedule
     */
    private function nextRun(array $schedule, string $timezone): ?string
    {
        if (! $schedule['enabled']) {
            return null;
        }

        $now = CarbonImmutable::now($timezone);

        $next = $this->backups->due($now)
            ? $now->addHour()->startOfHour()
            : $this->backups->nextScheduledAt($schedule, $now);

        return $next->format('Y-m-d H:i');
    }

    /**
     * What the backup named by ?restore= holds, or the reason it cannot be
     * read.
     *
     * @return array<string, mixed>|null
     */
    private function inspected(Request $request): ?array
    {
        $backup = ServerBackup::query()->find($request->integer('restore'));

        if ($backup === null) {
            return null;
        }

        try {
            $inspected = $this->backups->inspect($backup);
            unset($inspected['settings']);

            return $inspected;
        } catch (ValidationException $exception) {
            return ['name' => $backup->name, 'error' => collect($exception->errors())->flatten()->first()];
        }
    }
}
