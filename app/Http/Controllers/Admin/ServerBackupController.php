<?php

namespace App\Http\Controllers\Admin;

use App\Agent\AgentException;
use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Enums\ServerBackupStatus;
use App\Http\Controllers\Controller;
use App\Models\AccountRestore;
use App\Models\BackupRun;
use App\Models\Package;
use App\Models\ServerBackup;
use App\Models\SystemBackup;
use App\Models\Task;
use App\Models\User;
use App\Services\AccountRestoreService;
use App\Services\AuditLogger;
use App\Services\ServerBackupService;
use App\Services\SystemBackupService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The backups the server keeps of hosting accounts. Administrators set the
 * automatic backups (whether and when they run, how many are kept, the
 * disk they need) and use every backup; a reseller uses those of its own
 * accounts on the same pages (see ServerBackupPolicy). Both back up an
 * account by hand, restore a backup into its account, and make a deleted
 * account again from its backup.
 */
class ServerBackupController extends Controller
{
    public function __construct(
        private ServerBackupService $backups,
        private AccountRestoreService $restores,
        private SystemBackupService $systemBackups,
        private AuditLogger $audit,
    ) {}

    /**
     * The backups, the accounts that can be backed up, the latest account
     * restores, for administrators the schedule, the disk and the latest
     * runs, and with ?restore=<id> what that backup holds.
     */
    public function index(Request $request): Response
    {
        $viewer = $request->user();
        $search = $request->string('search')->trim()->toString();

        $backups = ServerBackup::query()
            ->visibleTo($viewer)
            ->withExists('account')
            ->when($search !== '', fn (Builder $query) => $query->where('username', 'like', '%'.addcslashes($search, '%_\\').'%'))
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
                'automatic' => ! $backup->isManual(),
                'can_delete' => Gate::forUser($viewer)->allows('delete', $backup),
                'created_at' => $backup->created_at?->toIso8601String(),
            ]);

        $props = [
            'scope' => $viewer->isAdmin() ? 'admin' : 'reseller',
            'backups' => $backups,
            'filters' => ['search' => $search],
            'accounts' => $this->accountsOf($viewer)->orderBy('username')->pluck('username'),
            'manual_keep' => ServerBackupService::MANUAL_KEEP,
            'restores' => $this->recentRestores($viewer),
            'inspected' => Inertia::optional(fn (): ?array => $this->inspected($request)),
        ];

        if (! $viewer->isAdmin()) {
            return Inertia::render('reseller/backups/index', $props);
        }

        $schedule = $this->backups->schedule();
        $timezone = $this->backups->timezone();
        $accounts = $this->backups->accounts();

        try {
            $space = $this->backups->space();
        } catch (AgentException $exception) {
            report($exception);
            $space = null;
        }

        return Inertia::render('admin/backups/index', [
            ...$props,
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
            'system' => [
                'settings' => $this->systemBackups->settings(),
                'keep_max' => SystemBackupService::MAX_KEEP,
                'backups' => SystemBackup::query()->latest('id')->limit(10)->get()->map(fn (SystemBackup $backup): array => [
                    'id' => $backup->id,
                    'name' => $backup->name,
                    'status' => $backup->status->value,
                    'size' => $backup->size_bytes,
                    'error' => $backup->error,
                    'created_at' => $backup->created_at?->toIso8601String(),
                ]),
            ],
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
     * Restore every account from its newest backup on this server, after
     * the panel was brought back on a new server.
     */
    public function restoreAll(Request $request): RedirectResponse
    {
        $result = $this->backups->restoreAll($request->user());

        $message = __('Restoring :count account(s). See Tasks for how it goes.', ['count' => $result['queued']]);

        if ($result['skipped'] !== []) {
            $message .= ' '.__('Not restored: :accounts', ['accounts' => implode('; ', array_slice($result['skipped'], 0, 5)).(count($result['skipped']) > 5 ? '; …' : '')]);
        }

        Inertia::flash('toast', ['type' => $result['queued'] > 0 ? 'success' : 'error', 'message' => $message]);

        return back();
    }

    /**
     * Back up one account now.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['username' => ['required', 'string', 'max:32']]);

        $account = $this->accountsOf($request->user())->where('username', $validated['username'])->first()
            ?? throw ValidationException::withMessages(['username' => __('Choose one of your hosting accounts.')]);

        $backup = $this->backups->backupNow($account, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Backing up :username; :name appears here when it is done.', ['username' => $account->username, 'name' => $backup->name])]);

        return back();
    }

    /**
     * Send the backup's file.
     */
    public function download(Request $request, ServerBackup $serverBackup): BinaryFileResponse
    {
        Gate::authorize('use', $serverBackup);

        abort_unless($serverBackup->status === ServerBackupStatus::Completed && is_file($serverBackup->path()), 404);

        $this->audit->record('backup.server_downloaded', $serverBackup->account, ['name' => $serverBackup->name, 'username' => $serverBackup->username], $request->user());

        return response()->download($serverBackup->path(), $serverBackup->name, ['Content-Type' => 'application/gzip']);
    }

    /**
     * Restore the parts of a backup asked for into its account.
     */
    public function restore(Request $request, ServerBackup $serverBackup): RedirectResponse
    {
        Gate::authorize('use', $serverBackup);

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

        return back();
    }

    /**
     * Make the deleted account of a backup again.
     */
    public function restoreAccount(Request $request, ServerBackup $serverBackup): RedirectResponse
    {
        Gate::authorize('use', $serverBackup);

        $owner = $this->restores->ownerFor($serverBackup, $request->user());

        $validated = $request->validate([
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'password' => ['nullable', 'string', Password::default()],
            'package_id' => ['nullable', 'integer', Rule::in(Package::query()->handedOutBy($owner)->pluck('id')->all())],
        ]);

        $account = $this->restores->restore($serverBackup, [
            'email' => $validated['email'] ?? null,
            'password' => $validated['password'] ?? null,
            'package_id' => isset($validated['package_id']) ? (int) $validated['package_id'] : null,
        ], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Making :username again. Its websites, databases, mail and files come back once the account is ready.', ['username' => $account->username])]);

        return back();
    }

    /**
     * Delete a backup for good.
     */
    public function destroy(Request $request, ServerBackup $serverBackup): RedirectResponse
    {
        Gate::authorize('delete', $serverBackup);

        $this->backups->delete($serverBackup, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Backup :name deleted.', ['name' => $serverBackup->name])]);

        return back();
    }

    /**
     * The hosting accounts the viewer may back up.
     *
     * @return Builder<User>
     */
    private function accountsOf(User $viewer): Builder
    {
        return User::query()
            ->where('role', Role::User)
            ->whereIn('status', [AccountStatus::Active, AccountStatus::Suspended])
            ->when(! $viewer->isAdmin(), fn (Builder $query) => $query->where('owner_id', $viewer->id));
    }

    /**
     * Accounts made again from a backup in the last week, with how their
     * restore went.
     *
     * @return list<array<string, mixed>>
     */
    private function recentRestores(User $viewer): array
    {
        $restores = AccountRestore::query()
            ->with('account:id,username,status')
            ->where('created_at', '>=', now()->subWeek())
            ->when(! $viewer->isAdmin(), fn (Builder $query) => $query->whereHas('account', fn (Builder $account) => $account->where('owner_id', $viewer->id)))
            ->latest('id')
            ->limit(10)
            ->get();

        $shown = [];

        foreach ($restores as $restore) {
            $task = $restore->status === AccountRestore::STATUS_RESTORING
                ? Task::query()->where('user_id', $restore->user_id)->where('action', 'backup.server_restore')->latest('id')->first()
                : null;

            $shown[] = [
                'id' => $restore->id,
                'username' => $restore->account->username,
                'status' => $task?->status->value ?? ($restore->status === AccountRestore::STATUS_WAITING ? 'pending' : $restore->status),
                'error' => $restore->error ?? $task?->error,
                'skipped' => $restore->skipped ?? [],
                'created_at' => $restore->created_at?->toIso8601String(),
            ];
        }

        return $shown;
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
     * read. For a deleted account, also what it is made again with: its
     * email, whether the backup keeps its password, and the packages its
     * owner hands out.
     *
     * @return array<string, mixed>|null
     */
    private function inspected(Request $request): ?array
    {
        $backup = ServerBackup::query()->visibleTo($request->user())->find($request->integer('restore'));

        if ($backup === null) {
            return null;
        }

        try {
            if ($backup->account !== null) {
                $inspected = $this->backups->inspect($backup);
                unset($inspected['settings']);

                return $inspected;
            }

            $inspected = $this->backups->read($backup, $backup->username);
        } catch (ValidationException $exception) {
            return ['name' => $backup->name, 'error' => collect($exception->errors())->flatten()->first()];
        }

        $saved = $this->restores->savedAccount($inspected['settings']);
        $owner = $this->restores->ownerFor($backup, $request->user());
        $packages = Package::query()->handedOutBy($owner)->orderBy('name')->get(['id', 'name']);

        return [
            ...array_diff_key($inspected, ['settings' => true]),
            'account' => [
                'username' => $backup->username,
                'email' => $saved['email'],
                'has_password' => $saved['password_hash'] !== null,
                'owner' => $owner->username,
                'package_id' => $packages->firstWhere('name', $saved['package'])?->id,
                'packages' => $packages->map(fn (Package $package): array => ['id' => $package->id, 'name' => $package->name])->all(),
                'domains' => collect((array) ($inspected['settings']['domains'] ?? []))->pluck('name')->filter(fn (mixed $name): bool => is_string($name))->values()->all(),
            ],
        ];
    }
}
