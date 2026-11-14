<?php

namespace App\Services;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\AccountStatus;
use App\Enums\BackupRunStatus;
use App\Enums\Role;
use App\Enums\ServerBackupStatus;
use App\Jobs\CreateServerBackup;
use App\Jobs\FinishBackupRun;
use App\Models\BackupRun;
use App\Models\ServerBackup;
use App\Models\Setting;
use App\Models\User;
use App\Rules\ValidUsername;
use App\Support\NotificationEvents;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The automatic backups the administrator turns on for every hosting
 * account, like WHM's: daily, weekly or monthly at an hour of the server's
 * timezone, keeping the newest few of each account. The agent keeps them
 * outside the homes (so they never count toward a quota); every account is
 * a job of its own on the "backups" queue, which has a worker of its own so
 * the panel's tasks never wait behind a backup.
 */
class ServerBackupService
{
    public const SETTING = 'backups.schedule';

    /**
     * The queue backups and their restores run on.
     */
    public const QUEUE = 'backups';

    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    public const MAX_KEEP = 30;

    /**
     * How many backups made by hand each account keeps; a new one deletes
     * the oldest beyond these.
     */
    public const MANUAL_KEEP = 3;

    /**
     * How long one backup of an account may take; the agent bounds it the
     * same way.
     */
    public const BACKUP_SECONDS = 6 * 3600;

    /**
     * A run whose backups have not moved for this long is closed.
     */
    private const STALE_HOURS = 7;

    /**
     * @var array{enabled: bool, frequency: string, hour: int, weekday: int, day: int, keep: int, files: bool, email: bool, databases: bool, enabled_at: string|null}
     */
    public const DEFAULTS = [
        'enabled' => false,
        'frequency' => 'daily',
        'hour' => 2,
        'weekday' => 0,
        'day' => 1,
        'keep' => 3,
        'files' => true,
        'email' => true,
        'databases' => true,
        'enabled_at' => null,
    ];

    public function __construct(
        private AgentClient $agent,
        private AuditLogger $audit,
        private BackupService $backups,
        private TaskDispatcher $tasks,
        private NotificationService $notifications,
    ) {}

    /**
     * The schedule as the administrator saved it, with the defaults for
     * what was never saved.
     *
     * @return array{enabled: bool, frequency: string, hour: int, weekday: int, day: int, keep: int, files: bool, email: bool, databases: bool, enabled_at: string|null}
     */
    public function schedule(): array
    {
        $saved = Setting::query()->where('key', self::SETTING)->value('value');

        /** @var array{enabled: bool, frequency: string, hour: int, weekday: int, day: int, keep: int, files: bool, email: bool, databases: bool, enabled_at: string|null} */
        return array_replace(self::DEFAULTS, array_intersect_key(is_array($saved) ? $saved : [], self::DEFAULTS));
    }

    /**
     * Save the schedule. Turning the backups on, or changing when they run,
     * starts the schedule from now: a time that already passed today does
     * not start a backup at once.
     *
     * @param  array{enabled: bool, frequency: string, hour: int, weekday: int, day: int, keep: int, files: bool, email: bool, databases: bool}  $values
     *
     * @throws ValidationException
     */
    public function saveSchedule(array $values, User $actor): void
    {
        if ($values['enabled'] && ! $values['files'] && ! $values['email'] && ! $values['databases']) {
            throw ValidationException::withMessages(['items' => __('Choose what the backups hold.')]);
        }

        $before = $this->schedule();
        $timing = array_flip(['frequency', 'hour', 'weekday', 'day']);
        $restarted = $values['enabled'] && (! $before['enabled'] || array_intersect_key($values, $timing) != array_intersect_key($before, $timing));

        Setting::query()->updateOrCreate(['key' => self::SETTING], ['value' => [
            ...$values,
            'enabled_at' => $restarted ? now()->toIso8601String() : ($values['enabled'] ? $before['enabled_at'] : null),
        ]]);

        $changed = array_keys(array_filter($values, fn (mixed $value, string $key): bool => $before[$key] !== $value, ARRAY_FILTER_USE_BOTH));

        if ($changed !== []) {
            $this->audit->record('backup.schedule_changed', null, [
                'from' => array_intersect_key($before, array_flip($changed)),
                'to' => array_intersect_key($values, array_flip($changed)),
            ], $actor);
        }
    }

    /**
     * The server's timezone, which the hour of the schedule is in.
     */
    public function timezone(): string
    {
        try {
            $timezone = (string) ($this->agent->call('system.settings', [], 20)['timezone'] ?? '');
        } catch (AgentException) {
            $timezone = '';
        }

        return in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : (string) config('app.timezone');
    }

    /**
     * The latest moment, at or before now, the schedule names. now must be
     * in the server's timezone.
     *
     * @param  array{frequency: string, hour: int, weekday: int, day: int}  $schedule
     */
    public function lastScheduledAt(array $schedule, CarbonImmutable $now): CarbonImmutable
    {
        $at = $now->setTime($schedule['hour'], 0);

        if ($schedule['frequency'] === 'weekly') {
            $at = $at->subDays(($now->dayOfWeek - $schedule['weekday'] + 7) % 7);

            return $at->greaterThan($now) ? $at->subWeek() : $at;
        }

        if ($schedule['frequency'] === 'monthly') {
            $at = $at->setDate($now->year, $now->month, $schedule['day']);

            return $at->greaterThan($now) ? $at->subMonthNoOverflow()->setDay($schedule['day']) : $at;
        }

        return $at->greaterThan($now) ? $at->subDay() : $at;
    }

    /**
     * The next moment, after now, the schedule names.
     *
     * @param  array{frequency: string, hour: int, weekday: int, day: int}  $schedule
     */
    public function nextScheduledAt(array $schedule, CarbonImmutable $now): CarbonImmutable
    {
        $last = $this->lastScheduledAt($schedule, $now);

        return match ($schedule['frequency']) {
            'weekly' => $last->addWeek(),
            'monthly' => $last->addMonthNoOverflow()->setDay($schedule['day']),
            default => $last->addDay(),
        };
    }

    /**
     * Whether the schedule names a moment, since it was turned on, after
     * which no scheduled run started yet. A run missed while the server
     * was down starts once it is up again.
     */
    public function due(CarbonImmutable $now): bool
    {
        $schedule = $this->schedule();

        if (! $schedule['enabled'] || $schedule['enabled_at'] === null) {
            return false;
        }

        $last = $this->lastScheduledAt($schedule, $now->setTimezone($this->timezone()));

        if ($last->lessThan(CarbonImmutable::parse($schedule['enabled_at']))) {
            return false;
        }

        return ! BackupRun::query()
            ->where('trigger', BackupRun::TRIGGER_SCHEDULE)
            ->where('started_at', '>=', $last->setTimezone((string) config('app.timezone')))
            ->exists();
    }

    /**
     * The run that is going, if any.
     */
    public function running(): ?BackupRun
    {
        return BackupRun::query()->where('status', BackupRunStatus::Running)->latest('id')->first();
    }

    /**
     * The size of the backups' disk, its free space, and what backups
     * always leave free on it, in bytes.
     *
     * @return array{total: int, free: int, reserve: int}
     *
     * @throws AgentException
     */
    public function space(): array
    {
        $space = $this->agent->call('backup.server_space', [], 20);

        return ['total' => (int) ($space['total'] ?? 0), 'free' => (int) ($space['free'] ?? 0), 'reserve' => (int) ($space['reserve'] ?? 0)];
    }

    /**
     * The hosting accounts a run backs up: those that exist on the server.
     *
     * @return Collection<int, User>
     */
    public function accounts(): Collection
    {
        return User::query()
            ->where('role', Role::User)
            ->whereIn('status', [AccountStatus::Active, AccountStatus::Suspended])
            ->orderBy('id')
            ->get();
    }

    /**
     * About how many bytes one run over accounts takes: the size of each
     * account's newest backup, or for an account never backed up its disk
     * use and databases (more than a compressed backup needs).
     *
     * @param  Collection<int, User>  $accounts
     */
    public function estimate(Collection $accounts): int
    {
        $newest = ServerBackup::query()
            ->whereIn('id', ServerBackup::query()->selectRaw('max(id)')->where('status', ServerBackupStatus::Completed)->whereNotNull('user_id')->groupBy('user_id'))
            ->pluck('size_bytes', 'user_id');

        $accounts->loadMissing('diskUsage')->loadSum('databases', 'size_bytes');

        $bytes = 0;

        foreach ($accounts as $account) {
            $bytes += $newest[$account->id] ?? ((int) ($account->diskUsage->disk_kb ?? 0) * 1024 + (int) $account->getAttribute('databases_sum_size_bytes'));
        }

        return $bytes;
    }

    /**
     * Start a run over every account: one job per account on the backups
     * queue, then one that closes the run. When the disk has no room for
     * the run besides its reserve, nothing is backed up and the
     * administrators hear about it.
     *
     * @throws ValidationException
     */
    public function start(string $trigger, ?User $actor = null): BackupRun
    {
        if ($this->running() !== null) {
            throw ValidationException::withMessages(['backup' => __('A backup run is still going.')]);
        }

        $schedule = $this->schedule();
        $accounts = $this->accounts();

        $run = BackupRun::create([
            'trigger' => $trigger,
            'status' => BackupRunStatus::Running,
            'accounts' => $accounts->count(),
            'started_at' => now(),
        ]);

        if ($actor !== null) {
            $this->audit->record('backup.run_started', null, ['run' => $run->id, 'accounts' => $accounts->count()], $actor);
        }

        if (($reason = $this->noRoom($accounts)) !== null) {
            $run->update(['status' => BackupRunStatus::Skipped, 'message' => $reason, 'finished_at' => now()]);

            $this->notifications->toAdmins(NotificationEvents::BACKUP, __('Automatic backups were skipped'), $reason, 'backup-run:'.$run->id);

            return $run;
        }

        $stamp = now()->format('Y-m-d-His');

        $backups = DB::transaction(fn (): array => $accounts->map(fn (User $account): ServerBackup => ServerBackup::create([
            'backup_run_id' => $run->id,
            'user_id' => $account->id,
            'owner_id' => $account->owner_id,
            'username' => $account->username,
            'name' => "{$account->username}-{$stamp}.tar.gz",
            'status' => ServerBackupStatus::Queued,
            'files' => $schedule['files'],
            'email' => $schedule['email'],
            'databases' => [],
        ]))->all());

        foreach ($backups as $backup) {
            CreateServerBackup::dispatch($backup, $schedule['databases']);
        }

        FinishBackupRun::dispatch($run);

        return $run;
    }

    /**
     * Back up one account now, outside the schedule, with all it holds. It
     * runs on the backups queue like the automatic backups; each account
     * keeps its newest MANUAL_KEEP of these.
     *
     * @throws ValidationException
     */
    public function backupNow(User $account, User $actor): ServerBackup
    {
        if (! $account->hasRole(Role::User) || ! in_array($account->status, [AccountStatus::Active, AccountStatus::Suspended], true)) {
            throw ValidationException::withMessages(['username' => __('Only an active or suspended hosting account can be backed up.')]);
        }

        if (ServerBackup::query()->where('user_id', $account->id)->whereIn('status', [ServerBackupStatus::Queued, ServerBackupStatus::Running])->exists()) {
            throw ValidationException::withMessages(['username' => __('A backup of :username is already being made.', ['username' => $account->username])]);
        }

        $backup = ServerBackup::create([
            'backup_run_id' => null,
            'user_id' => $account->id,
            'owner_id' => $account->owner_id,
            'username' => $account->username,
            'name' => $account->username.'-'.now()->format('Y-m-d-His').'-manual.tar.gz',
            'status' => ServerBackupStatus::Queued,
            'files' => true,
            'email' => true,
            'databases' => [],
        ]);

        CreateServerBackup::dispatch($backup, true);

        $this->audit->record('backup.server_created', $account, ['name' => $backup->name], $actor);

        return $backup;
    }

    /**
     * Make one backup: the job of an account in a run, or of one made by
     * hand.
     */
    public function create(ServerBackup $backup, bool $withDatabases): void
    {
        $backup->refresh();

        if ($backup->status !== ServerBackupStatus::Queued) {
            return;
        }

        $account = $backup->account;

        if ($account === null || ! in_array($account->status, [AccountStatus::Active, AccountStatus::Suspended], true)) {
            $this->fail($backup, __('The account no longer exists.'));

            return;
        }

        $databases = $withDatabases ? $this->backups->activeDatabases($account) : [];

        $backup->forceFill(['status' => ServerBackupStatus::Running, 'databases' => $databases, 'started_at' => now()])->save();

        try {
            $result = $this->agent->call('backup.server_create', [
                'username' => $account->username,
                'name' => $backup->name,
                'files' => $backup->files,
                'email' => $backup->email,
                'databases' => $databases,
                'manifest' => $this->backups->manifest($account, $backup->files, $backup->email, $databases),
                'settings' => $this->backups->settings($account),
            ], self::BACKUP_SECONDS);
        } catch (AgentException $exception) {
            $this->fail($backup, $this->reason($exception));

            return;
        }

        $backup->forceFill([
            'status' => ServerBackupStatus::Completed,
            'size_bytes' => (int) ($result['size'] ?? 0),
            'skipped' => (int) ($result['skipped'] ?? 0),
            'finished_at' => now(),
        ])->save();

        if ($backup->isManual()) {
            $manual = fn () => ServerBackup::query()
                ->whereNull('backup_run_id')
                ->where('user_id', $account->id)
                ->where('status', ServerBackupStatus::Completed);

            $kept = $manual()->orderByDesc('id')->limit(self::MANUAL_KEEP)->pluck('id');

            $this->deleteFiles($manual()->whereNotIn('id', $kept)->get(['id', 'name']));
        }
    }

    /**
     * Record that a backup failed.
     */
    public function fail(ServerBackup $backup, string $message): void
    {
        $backup->forceFill(['status' => ServerBackupStatus::Failed, 'error' => $message, 'finished_at' => now()])->save();
    }

    /**
     * Close a run: backups that never finished (their worker stopped)
     * failed. Then the oldest automatic backups beyond what is kept go, and
     * the administrators hear about failures.
     */
    public function finish(BackupRun $run): void
    {
        $run->refresh();

        if ($run->status !== BackupRunStatus::Running) {
            return;
        }

        $run->backups()
            ->whereIn('status', [ServerBackupStatus::Queued, ServerBackupStatus::Running])
            ->update(['status' => ServerBackupStatus::Failed, 'error' => __('The backup did not finish.'), 'finished_at' => now()]);

        $failed = $run->backups()->where('status', ServerBackupStatus::Failed)->orderBy('username')->get(['username', 'error']);

        $run->update([
            'status' => BackupRunStatus::Completed,
            'succeeded' => $run->backups()->where('status', ServerBackupStatus::Completed)->count(),
            'failed' => $failed->count(),
            'finished_at' => now(),
        ]);

        $this->rotate();

        if ($failed->isNotEmpty()) {
            $this->notifications->toAdmins(
                NotificationEvents::BACKUP,
                __('Automatic backup of :count account(s) failed', ['count' => $failed->count()]),
                $failed->take(10)->map(fn (ServerBackup $backup): string => "{$backup->username}: {$backup->error}")->implode("\n"),
                'backup-run:'.$run->id,
            );
        }
    }

    /**
     * Close a run that stopped moving: its last job was lost, or its worker
     * is down.
     */
    public function closeStale(): void
    {
        $run = $this->running();

        if ($run === null) {
            return;
        }

        $moved = $run->backups()->max('updated_at') ?? $run->started_at;

        if ($moved !== null && CarbonImmutable::parse($moved)->greaterThan(now()->subHours(self::STALE_HOURS))) {
            return;
        }

        $this->finish($run);
    }

    /**
     * Delete the automatic backups of each account beyond the newest it
     * keeps. Backups of deleted accounts stay until they are deleted by
     * hand; failed ones are forgotten after a month.
     */
    public function rotate(): void
    {
        $keep = $this->schedule()['keep'];

        $old = ServerBackup::query()
            ->whereNotNull('backup_run_id')
            ->whereNotNull('user_id')
            ->where('status', ServerBackupStatus::Completed)
            ->orderByDesc('id')
            ->get(['id', 'user_id', 'name'])
            ->groupBy('user_id')
            ->flatMap(fn (Collection $backups): Collection => $backups->slice($keep));

        $this->deleteFiles($old);

        ServerBackup::query()->where('status', ServerBackupStatus::Failed)->where('created_at', '<', now()->subDays(30))->delete();
        BackupRun::query()->where('created_at', '<', now()->subDays(90))->whereDoesntHave('backups')->delete();
    }

    /**
     * What a backup holds, for choosing what to restore into its account.
     *
     * @return array{name: string, created_at: string|null, files: bool, email: bool, databases: list<string>, mailboxes: list<string>, settings: array<string, mixed>}
     *
     * @throws ValidationException
     */
    public function inspect(ServerBackup $backup): array
    {
        return $this->read($backup, $this->restorableAccount($backup)->username);
    }

    /**
     * What a finished backup of the account username holds, as its manifest
     * and settings say.
     *
     * @return array{name: string, created_at: string|null, files: bool, email: bool, databases: list<string>, mailboxes: list<string>, settings: array<string, mixed>}
     *
     * @throws ValidationException
     */
    public function read(ServerBackup $backup, string $username): array
    {
        if ($backup->status !== ServerBackupStatus::Completed) {
            throw ValidationException::withMessages(['backup' => __('Only a finished backup can be restored.')]);
        }

        try {
            $meta = $this->agent->call('backup.server_inspect', ['name' => $backup->name]);
        } catch (AgentException $exception) {
            throw ValidationException::withMessages(['backup' => $this->reason($exception)]);
        }

        return $this->backups->describe($username, $backup->name, $meta);
    }

    /**
     * Queue the restore of the parts asked for into the account, as a task
     * on the backups queue. Databases, database users and mailboxes the
     * backup holds that the account no longer has come back first.
     *
     * @param  list<string>  $databases
     *
     * @throws ValidationException
     */
    public function restore(ServerBackup $backup, bool $files, bool $email, array $databases, User $actor): void
    {
        $account = $this->restorableAccount($backup);

        $this->backups->ensureReady($account);

        $described = $this->inspect($backup);

        DB::transaction(function () use ($account, $backup, $described, $files, $email, $databases, $actor): void {
            [$files, $email] = $this->backups->prepareRestore($account, $described, $files, $email, $databases, $actor);

            $this->tasks->dispatch('backup.server_restore', [
                'username' => $account->username,
                'name' => $backup->name,
                'files' => $files,
                'email' => $email,
                'databases' => $databases,
            ], $account, $actor);

            $this->audit->record('backup.server_restored', $account, ['name' => $backup->name, 'files' => $files, 'email' => $email, 'databases' => $databases], $actor);
        });
    }

    /**
     * Register the backups in the folder the panel does not know yet:
     * those copied there by hand, such as from another server. A backup of
     * a username that is an account here belongs to it; the others make
     * their account again. Returns what was added, and what was not a
     * RAYXO backup.
     *
     * @return array{added: list<string>, refused: list<string>}
     *
     * @throws ValidationException
     */
    public function scan(User $actor): array
    {
        try {
            $files = (array) ($this->agent->call('backup.server_list', [], 60)['backups'] ?? []);
        } catch (AgentException $exception) {
            throw ValidationException::withMessages(['backup' => $this->reason($exception)]);
        }

        $names = array_values(array_filter(array_map(fn (mixed $file): ?string => is_array($file) && is_string($file['name'] ?? null) ? $file['name'] : null, $files)));
        $known = ServerBackup::query()->whereIn('name', $names)->pluck('name')->all();
        $added = [];
        $refused = [];

        foreach ($files as $file) {
            if (! is_array($file) || ! is_string($file['name'] ?? null) || in_array($file['name'], $known, true)) {
                continue;
            }

            try {
                $meta = $this->agent->call('backup.server_inspect', ['name' => $file['name']]);
                $this->register($file['name'], (int) ($file['size'] ?? 0), $meta, $actor);
                $added[] = $file['name'];
            } catch (AgentException|ValidationException) {
                $refused[] = $file['name'];
            }
        }

        if ($added !== []) {
            $this->audit->record('backup.server_scanned', null, ['added' => $added], $actor);
        }

        return ['added' => $added, 'refused' => $refused];
    }

    /**
     * Remember a backup the agent keeps but did not make here, from the
     * manifest and settings it read at its start.
     *
     * @param  array<string, mixed>  $meta
     *
     * @throws ValidationException
     */
    public function register(string $name, int $size, array $meta, User $actor): ServerBackup
    {
        $manifest = json_decode((string) ($meta['manifest'] ?? ''), true);
        $username = is_array($manifest) ? ($manifest['username'] ?? null) : null;

        if (! is_array($manifest) || ($manifest['format'] ?? null) !== BackupService::FORMAT || ! is_string($username) || preg_match(ValidUsername::PATTERN, $username) !== 1) {
            throw ValidationException::withMessages(['backup' => __('This file is not a RAYXO backup.')]);
        }

        $account = User::query()->where('role', Role::User)->where('username', $username)->first();
        $databases = array_values(array_filter((array) ($manifest['databases'] ?? []), fn (mixed $database): bool => is_string($database) && str_starts_with($database, $username.'_')));

        $backup = new ServerBackup;
        $backup->forceFill([
            'backup_run_id' => null,
            'user_id' => $account?->id,
            'owner_id' => $account !== null ? $account->owner_id : $actor->id,
            'username' => $username,
            'name' => $name,
            'status' => ServerBackupStatus::Completed,
            'files' => ($manifest['files'] ?? false) === true,
            'email' => ($manifest['email'] ?? false) === true,
            'databases' => $databases,
            'size_bytes' => $size,
            'finished_at' => now(),
        ])->save();

        return $backup;
    }

    /**
     * Restore every active account from its newest finished backup whose
     * file is on this server: after the panel was brought back on a new
     * server and the backups were copied there. Returns how many restores
     * were queued, and why the others were not.
     *
     * @return array{queued: int, skipped: list<string>}
     */
    public function restoreAll(User $actor): array
    {
        $queued = 0;
        $skipped = [];

        foreach ($this->accounts() as $account) {
            $backup = ServerBackup::query()
                ->where('user_id', $account->id)
                ->where('status', ServerBackupStatus::Completed)
                ->latest('id')
                ->first();

            if ($backup === null || ! is_file($backup->path())) {
                $skipped[] = $account->username.': '.__('no backup of it is on this server');

                continue;
            }

            try {
                $this->restore($backup, $backup->files, $backup->email, $backup->databases, $actor);
                $queued++;
            } catch (ValidationException $exception) {
                $skipped[] = $account->username.': '.collect($exception->errors())->flatten()->first();
            }
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * Delete a backup for good.
     *
     * @throws ValidationException
     */
    public function delete(ServerBackup $backup, User $actor): void
    {
        if ($backup->status === ServerBackupStatus::Running) {
            throw ValidationException::withMessages(['backup' => __('Wait until this backup is done.')]);
        }

        try {
            $this->agent->call('backup.server_delete', ['names' => [$backup->name]], 60);
        } catch (AgentException $exception) {
            throw ValidationException::withMessages(['backup' => $this->reason($exception)]);
        }

        $backup->delete();

        $this->audit->record('backup.server_deleted', $backup->account, ['name' => $backup->name, 'username' => $backup->username], $actor);
    }

    /**
     * Delete the files of backups, then forget them. Those the agent could
     * not delete stay for the next try.
     *
     * @param  SupportCollection<int, ServerBackup>  $backups
     */
    private function deleteFiles(SupportCollection $backups): void
    {
        foreach ($backups->chunk(500) as $chunk) {
            try {
                $this->agent->call('backup.server_delete', ['names' => $chunk->pluck('name')->values()->all()], 120);
            } catch (AgentException $exception) {
                report($exception);

                continue;
            }

            ServerBackup::query()->whereKey($chunk->pluck('id')->all())->delete();
        }
    }

    /**
     * Why the disk has no room for a run over accounts, or null when it has.
     *
     * @param  Collection<int, User>  $accounts
     */
    private function noRoom(Collection $accounts): ?string
    {
        try {
            $space = $this->space();
        } catch (AgentException $exception) {
            report($exception);

            return __('The server agent did not answer: :message', ['message' => $exception->getMessage()]);
        }

        $needed = $this->estimate($accounts);

        if ($space['reserve'] <= $space['free'] - $needed) {
            return null;
        }

        return __('The backups need about :needed GB, but the disk has :free GB free and backups always leave :reserve GB. Free some space or keep fewer backups.', [
            'needed' => $this->gigabytes($needed),
            'free' => $this->gigabytes($space['free']),
            'reserve' => $this->gigabytes($space['reserve']),
        ]);
    }

    /**
     * The account a backup is restored into: its own, while it exists.
     *
     * @throws ValidationException
     */
    private function restorableAccount(ServerBackup $backup): User
    {
        if ($backup->status !== ServerBackupStatus::Completed) {
            throw ValidationException::withMessages(['backup' => __('Only a finished backup can be restored.')]);
        }

        $account = $backup->account;

        if ($account === null) {
            throw ValidationException::withMessages(['backup' => __('The account of this backup no longer exists; restore the account from it instead.')]);
        }

        return $account;
    }

    /**
     * What an agent error about backups means for the administrator.
     */
    public function reason(AgentException $exception): string
    {
        return match ($exception->reason) {
            'no_space' => __('The server disk does not have enough free space for backups.'),
            'not_found' => __('The file of this backup is gone.'),
            default => $exception->getMessage(),
        };
    }

    private function gigabytes(int $bytes): string
    {
        return number_format($bytes / 1024 ** 3, 1);
    }
}
