<?php

namespace App\Services;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\Role;
use App\Enums\ServerBackupStatus;
use App\Jobs\CreateSystemBackup;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\SystemBackup;
use App\Models\User;
use App\Support\NotificationEvents;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Backups of the panel itself: a dump of its database, its configuration
 * in /etc/rayxo (with the key that decrypts its data), the DKIM keys mail
 * is signed with, and the files people uploaded. They are made every day
 * unless the administrator turns them off, and are small next to the
 * backups of accounts. Only root reads them on the server; setup.sh
 * --restore-system brings a panel back from one on a new server.
 */
class SystemBackupService
{
    public const SETTING = 'backups.system';

    public const MAX_KEEP = 60;

    /**
     * @var array{enabled: bool, keep: int}
     */
    public const DEFAULTS = ['enabled' => true, 'keep' => 7];

    /**
     * A backup is made once this many hours passed since the last one.
     */
    private const EVERY_HOURS = 23;

    public function __construct(
        private AgentClient $agent,
        private AuditLogger $audit,
        private NotificationService $notifications,
    ) {}

    /**
     * Whether the panel is backed up every day, and how many backups are
     * kept.
     *
     * @return array{enabled: bool, keep: int}
     */
    public function settings(): array
    {
        $saved = Setting::query()->where('key', self::SETTING)->value('value');

        /** @var array{enabled: bool, keep: int} */
        return array_replace(self::DEFAULTS, array_intersect_key(is_array($saved) ? $saved : [], self::DEFAULTS));
    }

    public function saveSettings(bool $enabled, int $keep, User $actor): void
    {
        $before = $this->settings();
        $after = ['enabled' => $enabled, 'keep' => $keep];

        if ($before === $after) {
            return;
        }

        Setting::query()->updateOrCreate(['key' => self::SETTING], ['value' => $after]);

        $this->audit->record('backup.system_settings_changed', null, ['from' => $before, 'to' => $after], $actor);
    }

    /**
     * Whether the daily backup is due: on, and none made or going in the
     * last day.
     */
    public function due(): bool
    {
        return $this->settings()['enabled'] && ! SystemBackup::query()
            ->where(fn ($query) => $query
                ->whereIn('status', [ServerBackupStatus::Queued, ServerBackupStatus::Running])
                ->orWhere(fn ($recent) => $recent->where('status', ServerBackupStatus::Completed)->where('created_at', '>', now()->subHours(self::EVERY_HOURS))))
            ->exists();
    }

    /**
     * Queue a backup of the panel on the backups queue.
     *
     * @throws ValidationException
     */
    public function start(?User $actor = null): SystemBackup
    {
        if (SystemBackup::query()->whereIn('status', [ServerBackupStatus::Queued, ServerBackupStatus::Running])->exists()) {
            throw ValidationException::withMessages(['system' => __('A backup of the panel is already being made.')]);
        }

        $backup = SystemBackup::create([
            'name' => 'rayxo-system-'.$this->hostname().'-'.now()->format('Y-m-d-His').'.tar.gz',
            'status' => ServerBackupStatus::Queued,
        ]);

        CreateSystemBackup::dispatch($backup);

        if ($actor !== null) {
            $this->audit->record('backup.system_created', null, ['name' => $backup->name], $actor);
        }

        return $backup;
    }

    /**
     * Make the backup: the job queued by start(). The oldest beyond what
     * is kept go once it is done; a failure tells the administrators.
     */
    public function create(SystemBackup $backup): void
    {
        $backup->refresh();

        if ($backup->status !== ServerBackupStatus::Queued) {
            return;
        }

        $backup->forceFill(['status' => ServerBackupStatus::Running])->save();

        try {
            $result = $this->agent->call('backup.system_create', [
                'name' => $backup->name,
                'manifest' => [
                    'format' => 1,
                    'panel' => 'RAYXO',
                    'hostname' => $this->hostname(),
                    'created_at' => now()->toIso8601String(),
                    'accounts' => User::query()->where('role', Role::User)->count(),
                    'domains' => Domain::query()->count(),
                ],
            ], 3600);
        } catch (AgentException $exception) {
            $this->fail($backup, $exception->reason === 'no_space'
                ? __('The server disk does not have enough free space for backups.')
                : $exception->getMessage());

            return;
        }

        $backup->forceFill([
            'status' => ServerBackupStatus::Completed,
            'size_bytes' => (int) ($result['size'] ?? 0),
            'finished_at' => now(),
        ])->save();

        $this->rotate();
    }

    public function fail(SystemBackup $backup, string $message): void
    {
        $backup->forceFill(['status' => ServerBackupStatus::Failed, 'error' => $message, 'finished_at' => now()])->save();

        $this->notifications->toAdmins(NotificationEvents::BACKUP, __('The backup of the panel failed'), $message, 'system-backup-failed');
    }

    /**
     * Delete the backups of the panel beyond the newest it keeps; failed
     * ones are forgotten after a week.
     */
    public function rotate(): void
    {
        $kept = SystemBackup::query()
            ->where('status', ServerBackupStatus::Completed)
            ->orderByDesc('id')
            ->limit($this->settings()['keep'])
            ->pluck('id');

        $old = SystemBackup::query()
            ->where('status', ServerBackupStatus::Completed)
            ->whereNotIn('id', $kept)
            ->get(['id', 'name']);

        if ($old->isNotEmpty()) {
            try {
                $this->agent->call('backup.system_delete', ['names' => $old->pluck('name')->all()], 60);
                SystemBackup::query()->whereKey($old->pluck('id')->all())->delete();
            } catch (AgentException $exception) {
                report($exception);
            }
        }

        SystemBackup::query()->where('status', ServerBackupStatus::Failed)->where('created_at', '<', now()->subWeek())->delete();
    }

    /**
     * Copy a backup to a download only the panel reads, and return where
     * it is and the name it is sent with.
     *
     * @return array{file: string, name: string}
     *
     * @throws ValidationException
     */
    public function download(SystemBackup $backup, User $actor): array
    {
        if ($backup->status !== ServerBackupStatus::Completed) {
            throw ValidationException::withMessages(['system' => __('Only a finished backup can be downloaded.')]);
        }

        $transfer = Str::random(48);

        try {
            $this->agent->call('backup.system_download', ['name' => $backup->name, 'transfer' => $transfer], 600);
        } catch (AgentException $exception) {
            throw ValidationException::withMessages(['system' => $exception->reason === 'not_found'
                ? __('The file of this backup is gone.')
                : $exception->getMessage()]);
        }

        $this->audit->record('backup.system_downloaded', null, ['name' => $backup->name], $actor);

        return ['file' => FileManagerService::transferPath($transfer.'.download'), 'name' => $backup->name];
    }

    /**
     * Delete a backup for good.
     *
     * @throws ValidationException
     */
    public function delete(SystemBackup $backup, User $actor): void
    {
        if ($backup->status === ServerBackupStatus::Running) {
            throw ValidationException::withMessages(['system' => __('Wait until this backup is done.')]);
        }

        try {
            $this->agent->call('backup.system_delete', ['names' => [$backup->name]], 60);
        } catch (AgentException $exception) {
            throw ValidationException::withMessages(['system' => $exception->getMessage()]);
        }

        $backup->delete();

        $this->audit->record('backup.system_deleted', null, ['name' => $backup->name], $actor);
    }

    /**
     * The panel's hostname, as a backup's name may hold it.
     */
    private function hostname(): string
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        return Str::limit(preg_replace('/[^A-Za-z0-9.-]/', '', $host) ?: 'panel', 60, '');
    }
}
