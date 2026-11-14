<?php

namespace App\Services;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\AccountStatus;
use App\Enums\DatabaseStatus;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\CronJob;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\MailForwarder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Backups a hosting account makes of itself: a .tar.gz in its own
 * ~/backups (so it counts toward its disk quota) with the files of its
 * home, its mail, the dumps of its databases, and the panel's settings of
 * the account, from which a restore brings back missing databases, their
 * users and mailboxes with the passwords they had.
 */
class BackupService
{
    /**
     * The version of the manifest and the settings a backup holds.
     */
    public const FORMAT = 1;

    /**
     * What a backup's file is named after the account's username.
     */
    public const NAME_PATTERN = '[A-Za-z0-9][A-Za-z0-9._-]{0,150}\.tar\.gz';

    private const TASKS = ['backup.create', 'backup.restore'];

    public function __construct(
        private TaskDispatcher $tasks,
        private AgentClient $agent,
        private AuditLogger $audit,
        private DatabaseService $databases,
        private MailService $mail,
    ) {}

    /**
     * The backups in the account's ~/backups, newest first.
     *
     * @return list<array{name: string, size: int, modified: int}>
     *
     * @throws ValidationException
     */
    public function list(User $account): array
    {
        try {
            $listing = $this->agent->call('fs.list', ['username' => $account->username, 'path' => 'backups']);
        } catch (AgentException $exception) {
            if ($exception->reason === 'not_found') {
                return [];
            }

            throw $this->failure($exception);
        }

        $backups = [];

        foreach ((array) ($listing['entries'] ?? []) as $entry) {
            if (is_array($entry) && ($entry['type'] ?? null) === 'file' && preg_match('/^'.self::NAME_PATTERN.'$/', (string) ($entry['name'] ?? '')) === 1) {
                $backups[] = ['name' => (string) $entry['name'], 'size' => (int) ($entry['size'] ?? 0), 'modified' => (int) ($entry['modified'] ?? 0)];
            }
        }

        usort($backups, fn (array $a, array $b): int => $b['modified'] <=> $a['modified']);

        return $backups;
    }

    /**
     * Whether a backup or restore of the account is queued or running.
     */
    public function busy(User $account): bool
    {
        return Task::query()
            ->where('user_id', $account->id)
            ->whereIn('action', self::TASKS)
            ->whereIn('status', [TaskStatus::Queued, TaskStatus::Running])
            ->exists();
    }

    /**
     * Queue a backup of the account with the parts asked for, and return
     * the name its file will have.
     *
     * @throws ValidationException
     */
    public function create(User $account, bool $files, bool $email, bool $databases, User $actor): string
    {
        $this->ensureReady($account);

        if (! $files && ! $email && ! $databases) {
            throw ValidationException::withMessages(['items' => __('Choose what the backup holds.')]);
        }

        $name = $account->username.'-'.now()->format('Y-m-d-His').'.tar.gz';
        $databaseNames = $databases ? $this->activeDatabases($account) : [];

        $this->tasks->dispatch('backup.create', [
            'username' => $account->username,
            'name' => $name,
            'files' => $files,
            'email' => $email,
            'databases' => $databaseNames,
            'manifest' => [
                'format' => self::FORMAT,
                'panel' => 'RAYXO',
                'username' => $account->username,
                'created_at' => now()->toIso8601String(),
                'files' => $files,
                'email' => $email,
                'databases' => $databaseNames,
            ],
            'settings' => $this->settings($account),
        ], $account, $actor);

        $this->audit->record('backup.created', $account, ['name' => $name, 'files' => $files, 'email' => $email, 'databases' => $databaseNames], $actor);

        return $name;
    }

    /**
     * What a backup of the account holds, for choosing what to restore.
     *
     * @return array{name: string, created_at: string|null, files: bool, email: bool, databases: list<string>, mailboxes: list<string>, settings: array<string, mixed>}
     *
     * @throws ValidationException
     */
    public function inspect(User $account, string $name): array
    {
        $this->ensureName($name);

        try {
            $meta = $this->agent->call('backup.inspect', ['username' => $account->username, 'name' => $name]);
        } catch (AgentException $exception) {
            throw $this->failure($exception);
        }

        $manifest = json_decode((string) ($meta['manifest'] ?? ''), true);
        $settings = json_decode((string) ($meta['settings'] ?? ''), true);

        if (! is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT || ! is_array($settings)) {
            throw ValidationException::withMessages(['backup' => __('This file is not a RAYXO backup.')]);
        }

        if (($manifest['username'] ?? null) !== $account->username) {
            throw ValidationException::withMessages(['backup' => __('This backup belongs to another account.')]);
        }

        $databases = array_values(array_filter((array) ($manifest['databases'] ?? []), fn (mixed $database): bool => is_string($database) && str_starts_with($database, $account->username.'_')));

        return [
            'name' => $name,
            'created_at' => is_string($manifest['created_at'] ?? null) ? $manifest['created_at'] : null,
            'files' => ($manifest['files'] ?? false) === true,
            'email' => ($manifest['email'] ?? false) === true,
            'databases' => $databases,
            'mailboxes' => array_map(
                fn (array $mailbox): string => $mailbox['local_part'].'@'.$mailbox['domain'],
                $this->mailboxesOf($settings),
            ),
            'settings' => $settings,
        ];
    }

    /**
     * Queue the restore of the parts asked for. Databases, database users
     * and mailboxes the backup holds that the account no longer has come
     * back first, within the package's limits; the data then replaces what
     * is there.
     *
     * @param  list<string>  $databases  Of those the backup holds.
     *
     * @throws ValidationException
     */
    public function restore(User $account, string $name, bool $files, bool $email, array $databases, User $actor): void
    {
        $this->ensureReady($account);

        $backup = $this->inspect($account, $name);
        $files = $files && $backup['files'];
        $email = $email && $backup['email'];

        if ($unknown = array_diff($databases, $backup['databases'])) {
            throw ValidationException::withMessages(['databases' => __('The backup does not hold database :name.', ['name' => implode(', ', $unknown)])]);
        }

        if (! $files && ! $email && $databases === []) {
            throw ValidationException::withMessages(['items' => __('Choose what to restore.')]);
        }

        DB::transaction(function () use ($account, $name, $files, $email, $databases, $backup, $actor): void {
            $this->bringBackDatabases($account, $databases, $backup['settings'], $actor);

            if ($email) {
                $this->bringBackMailboxes($account, $backup['settings'], $actor);
            }

            $this->tasks->dispatch('backup.restore', [
                'username' => $account->username,
                'name' => $name,
                'files' => $files,
                'email' => $email,
                'databases' => $databases,
            ], $account, $actor);

            $this->audit->record('backup.restored', $account, ['name' => $name, 'files' => $files, 'email' => $email, 'databases' => $databases], $actor);
        });
    }

    /**
     * Delete a backup for good, freeing its space at once.
     *
     * @throws ValidationException
     */
    public function delete(User $account, string $name, User $actor): void
    {
        $this->ensureName($name);

        if ($this->busy($account)) {
            throw ValidationException::withMessages(['backup' => __('Wait until the backup or restore that is running is done.')]);
        }

        try {
            $this->agent->call('backup.delete', ['username' => $account->username, 'name' => $name]);
        } catch (AgentException $exception) {
            throw $this->failure($exception);
        }

        $this->audit->record('backup.deleted', $account, ['name' => $name], $actor);
    }

    /**
     * The panel's settings of the account a backup holds. A restore reads
     * the databases, their users and the mailboxes from it; the rest is
     * there for restoring a whole account later.
     *
     * @return array<string, mixed>
     */
    public function settings(User $account): array
    {
        $mailboxes = Mailbox::query()
            ->with('mailDomain.domain')
            ->whereHas('mailDomain.domain', fn (Builder $query) => $query->where('user_id', $account->id))
            ->orderBy('id')
            ->get();

        $forwarders = MailForwarder::query()
            ->with('mailDomain.domain')
            ->whereHas('mailDomain.domain', fn (Builder $query) => $query->where('user_id', $account->id))
            ->orderBy('id')
            ->get();

        return [
            'format' => self::FORMAT,
            'domains' => $account->domains()->with('parent')->orderBy('id')->get()->map(fn (Domain $domain): array => [
                'name' => $domain->name,
                'type' => $domain->type->value,
                'parent' => $domain->parent?->name,
                'php_version' => $domain->php_version,
                'is_primary' => $domain->is_primary,
            ])->all(),
            'databases' => $this->activeDatabases($account),
            'database_users' => $account->databaseUsers()->with('databases')->orderBy('name')->get()->map(fn (DatabaseUser $user): array => [
                'name' => $user->name,
                'password_hash' => $user->password_hash,
                'databases' => $user->databases->pluck('name')->sort()->values()->all(),
            ])->all(),
            'mailboxes' => $mailboxes->map(fn (Mailbox $mailbox): array => [
                'domain' => $mailbox->mailDomain->domain->name,
                'local_part' => $mailbox->local_part,
                'password_hash' => $mailbox->password_hash,
                'quota_mb' => $mailbox->quota_mb,
            ])->all(),
            'forwarders' => $forwarders->map(fn (MailForwarder $forwarder): array => [
                'domain' => $forwarder->mailDomain->domain->name,
                'local_part' => $forwarder->local_part,
                'destinations' => $forwarder->destinations,
            ])->all(),
            'cron_jobs' => $account->cronJobs()->orderBy('id')->get()->map(fn (CronJob $job): array => [
                'schedule' => $job->schedule,
                'command' => $job->command,
                'description' => $job->description,
                'enabled' => $job->enabled,
            ])->all(),
        ];
    }

    /**
     * Create the databases that are gone, then the users the backup gave
     * them, with their passwords.
     *
     * @param  list<string>  $names
     * @param  array<string, mixed>  $settings
     *
     * @throws ValidationException
     */
    private function bringBackDatabases(User $account, array $names, array $settings, User $actor): void
    {
        if ($names === []) {
            return;
        }

        $prefix = $account->username.'_';
        $existing = $account->databases()->pluck('name')->all();

        foreach (array_diff($names, $existing) as $name) {
            $this->databases->createDatabase($account, substr($name, strlen($prefix)), $actor);
        }

        $users = $account->databaseUsers()->pluck('name')->all();
        $ids = $account->databases()->whereIn('name', $names)->pluck('id', 'name');

        foreach ((array) ($settings['database_users'] ?? []) as $user) {
            if (! is_array($user) || ! is_string($user['name'] ?? null) || ! str_starts_with($user['name'], $prefix) || in_array($user['name'], $users, true)) {
                continue;
            }

            $access = array_values(array_filter(array_map(fn (mixed $name): ?int => is_string($name) ? $ids[$name] ?? null : null, (array) ($user['databases'] ?? []))));

            if ($access === []) {
                continue;
            }

            $this->databases->restoreUser($account, substr($user['name'], strlen($prefix)), (string) ($user['password_hash'] ?? ''), $access, $actor);
        }
    }

    /**
     * Create the mailboxes of the backup that are gone, on domains the
     * account still has.
     *
     * @param  array<string, mixed>  $settings
     *
     * @throws ValidationException
     */
    private function bringBackMailboxes(User $account, array $settings, User $actor): void
    {
        $domains = $account->domains()->pluck('id', 'name');

        foreach ($this->mailboxesOf($settings) as $mailbox) {
            $domainId = $domains[$mailbox['domain']] ?? null;

            $exists = Mailbox::query()
                ->where('local_part', $mailbox['local_part'])
                ->whereHas('mailDomain', fn (Builder $query) => $query->where('domain_id', $domainId))
                ->exists();

            if ($domainId === null || $exists || preg_match('/^[a-z0-9](?:[a-z0-9._+-]{0,62}[a-z0-9])?$/', $mailbox['local_part']) !== 1) {
                continue;
            }

            $this->mail->restoreMailbox($account, $domainId, $mailbox['local_part'], $mailbox['password_hash'], $mailbox['quota_mb'], $actor);
        }
    }

    /**
     * The well-formed mailboxes of a backup's settings.
     *
     * @param  array<string, mixed>  $settings
     * @return list<array{domain: string, local_part: string, password_hash: string, quota_mb: int|null}>
     */
    private function mailboxesOf(array $settings): array
    {
        $mailboxes = [];

        foreach ((array) ($settings['mailboxes'] ?? []) as $mailbox) {
            if (is_array($mailbox) && is_string($mailbox['domain'] ?? null) && is_string($mailbox['local_part'] ?? null) && is_string($mailbox['password_hash'] ?? null)) {
                $quota = $mailbox['quota_mb'] ?? null;
                $mailboxes[] = [
                    'domain' => $mailbox['domain'],
                    'local_part' => $mailbox['local_part'],
                    'password_hash' => $mailbox['password_hash'],
                    'quota_mb' => is_int($quota) && $quota > 0 ? $quota : null,
                ];
            }
        }

        return $mailboxes;
    }

    /**
     * @return list<string>
     */
    private function activeDatabases(User $account): array
    {
        $names = [];

        foreach ($account->databases()->where('status', DatabaseStatus::Active)->orderBy('name')->get() as $database) {
            $names[] = $database->name;
        }

        return $names;
    }

    /**
     * @throws ValidationException
     */
    private function ensureReady(User $account): void
    {
        if (! $account->hasRole(Role::User) || $account->status !== AccountStatus::Active) {
            throw ValidationException::withMessages(['backup' => __('Backups can only be made and restored while the account is active.')]);
        }

        if ($this->busy($account)) {
            throw ValidationException::withMessages(['backup' => __('Wait until the backup or restore that is running is done.')]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function ensureName(string $name): void
    {
        if (preg_match('/^'.self::NAME_PATTERN.'$/', $name) !== 1) {
            throw ValidationException::withMessages(['backup' => __('This file is not a RAYXO backup.')]);
        }
    }

    private function failure(AgentException $exception): ValidationException
    {
        if ($exception->reason === null) {
            report($exception);
        }

        return ValidationException::withMessages(['backup' => FileManagerService::reasonMessage($exception->reason)]);
    }
}
