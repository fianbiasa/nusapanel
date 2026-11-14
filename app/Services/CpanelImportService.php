<?php

namespace App\Services;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Jobs\ImportCpanelAccount;
use App\Models\CpanelImport;
use App\Models\Domain;
use App\Models\ServerBackup;
use App\Models\User;
use App\Rules\ValidUsername;
use App\Support\CpanelBackup;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Hosting accounts imported from cPanel. The administrator makes a backup
 * of the account in cPanel (pkgacct, or a full backup) and copies it into
 * the backups folder here. A job then has the agent read it, turns what it
 * holds into a RAYXO backup (see CpanelBackup), and makes the account again
 * from that, as from any backup of a deleted account.
 */
class CpanelImportService
{
    /**
     * How long the agent may read or write one backup.
     */
    public const SECONDS = 6 * 3600;

    public function __construct(
        private AgentClient $agent,
        private ServerBackupService $backups,
        private AccountRestoreService $restores,
        private PhpVersionService $phpVersions,
        private AuditLogger $audit,
    ) {}

    /**
     * The files in the backups folder that are not backups the panel
     * knows: those copied there by hand, cPanel backups among them. The
     * newest first.
     *
     * @return list<array{name: string, size: int, modified: int}>
     *
     * @throws ValidationException
     */
    public function files(): array
    {
        try {
            $listed = (array) ($this->agent->call('backup.server_list', [], 60)['backups'] ?? []);
        } catch (AgentException $exception) {
            throw ValidationException::withMessages(['file' => $this->backups->reason($exception)]);
        }

        $files = [];

        foreach ($listed as $file) {
            if (is_array($file) && is_string($file['name'] ?? null)) {
                $files[] = ['name' => $file['name'], 'size' => (int) ($file['size'] ?? 0), 'modified' => (int) ($file['modified'] ?? 0)];
            }
        }

        $known = ServerBackup::query()->whereIn('name', array_column($files, 'name'))->pluck('name')->all();
        $files = array_values(array_filter($files, fn (array $file): bool => ! in_array($file['name'], $known, true)));
        usort($files, fn (array $a, array $b): int => $b['modified'] <=> $a['modified']);

        return $files;
    }

    /**
     * Queue the import of a cPanel backup in the folder. Without a username
     * the account keeps its cPanel username, and without an email address
     * the cPanel account's contact address.
     *
     * @param  array{file: string, username?: string|null, email?: string|null, password: string, package_id?: int|null}  $data
     *
     * @throws ValidationException
     */
    public function start(array $data, User $actor): CpanelImport
    {
        if (! in_array($data['file'], array_column($this->files(), 'name'), true)) {
            throw ValidationException::withMessages(['file' => __('This file is not in the backups folder.')]);
        }

        $going = CpanelImport::query()->where('file', $data['file'])->whereIn('status', [CpanelImport::STATUS_QUEUED, CpanelImport::STATUS_READING, CpanelImport::STATUS_CONVERTING])->exists();

        if ($going) {
            throw ValidationException::withMessages(['file' => __('This file is being imported already.')]);
        }

        $import = new CpanelImport;
        $import->forceFill([
            'actor_id' => $actor->id,
            'file' => $data['file'],
            'username' => ($data['username'] ?? null) ?: null,
            'email' => ($data['email'] ?? null) ?: null,
            'password_hash' => Hash::make($data['password']),
            'package_id' => $data['package_id'] ?? null,
            'status' => CpanelImport::STATUS_QUEUED,
        ])->save();

        $this->audit->record('backup.cpanel_import_started', null, ['file' => $import->file, 'username' => $import->username], $actor);

        ImportCpanelAccount::dispatch($import);

        return $import;
    }

    /**
     * Import one account: the job queued by start().
     */
    public function run(CpanelImport $import): void
    {
        $import->refresh();

        if ($import->status !== CpanelImport::STATUS_QUEUED || $import->password_hash === null) {
            return;
        }

        $actor = User::query()->find($import->actor_id) ?? User::query()->where('role', 'admin')->orderBy('id')->first();

        try {
            if ($actor === null) {
                throw ValidationException::withMessages(['import' => __('No administrator is left to import the account for.')]);
            }

            $this->step($import, CpanelImport::STATUS_READING);
            $backup = CpanelBackup::read($this->call('backup.cpanel_inspect', ['name' => $import->file]));
            $username = $import->username ?? $backup->user;
            $import->forceFill(['cpanel_user' => $backup->user])->save();

            $this->ensureFree($username, $import->email ?? $backup->contactEmail());
            $plan = $backup->plan($username, $this->phpVersions->available());
            $this->ensureDomainFree($plan['settings']['domains'][0]['name']);

            $this->step($import, CpanelImport::STATUS_CONVERTING);
            $name = $username.'-'.now()->format('Y-m-d-His').'-cpanel.tar.gz';
            $converted = $this->call('backup.cpanel_convert', [
                'source' => $import->file,
                'name' => $name,
                'top' => $backup->top,
                'manifest' => $plan['manifest'],
                'settings' => $plan['settings'],
                'databases' => $plan['databases'],
                'rules' => $plan['rules'],
            ]);

            $server = $this->backups->register($name, (int) ($converted['size'] ?? 0), $converted, $actor);
            $import->forceFill(['server_backup_id' => $server->id, 'notes' => $plan['notes']])->save();

            $account = $this->restores->restore($server, [
                'email' => $import->email,
                'password' => $import->password_hash,
                'package_id' => $import->package_id,
            ], $actor);

            $import->forceFill(['status' => CpanelImport::STATUS_RESTORING, 'user_id' => $account->id, 'password_hash' => null])->save();
            $this->audit->record('backup.cpanel_imported', $account, ['file' => $import->file, 'username' => $account->username], $actor);
        } catch (ValidationException $exception) {
            $this->fail($import, (string) collect($exception->errors())->flatten()->first());
        }
    }

    /**
     * Fail imports whose job stopped without a word (a worker that was
     * killed): none takes this long.
     */
    public function closeStale(): void
    {
        CpanelImport::query()
            ->whereIn('status', [CpanelImport::STATUS_QUEUED, CpanelImport::STATUS_READING, CpanelImport::STATUS_CONVERTING])
            ->where('updated_at', '<', now()->subHours(13))
            ->each(fn (CpanelImport $import) => $this->fail($import, __('The import did not finish.')));
    }

    /**
     * Record that an import failed; the new password is forgotten.
     */
    public function fail(CpanelImport $import, string $message): void
    {
        $import->forceFill(['status' => CpanelImport::STATUS_FAILED, 'error' => $message, 'password_hash' => null])->save();
    }

    private function step(CpanelImport $import, string $status): void
    {
        $import->forceFill(['status' => $status])->save();
    }

    /**
     * Refuse before the long conversion what would stop the account from
     * being made: a username that is not one here, or taken, or an email
     * address another account has.
     *
     * @throws ValidationException
     */
    private function ensureFree(string $username, ?string $email): void
    {
        if (preg_match(ValidUsername::PATTERN, $username) !== 1 || in_array($username, ValidUsername::RESERVED, true)) {
            throw ValidationException::withMessages(['username' => __('The cPanel username :username cannot be used here; enter another username.', ['username' => $username])]);
        }

        if (User::query()->where('username', $username)->exists()) {
            throw ValidationException::withMessages(['username' => __('The username :username is taken; enter another username.', ['username' => $username])]);
        }

        if ($email !== null && User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => __('Another account uses the email address :email; enter another one.', ['email' => $email])]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function ensureDomainFree(string $domain): void
    {
        if (Domain::query()->where('name', $domain)->exists()) {
            throw ValidationException::withMessages(['domain' => __('The domain :domain is on this server already.', ['domain' => $domain])]);
        }
    }

    /**
     * Call the agent about a backup, for as long as a backup may take.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function call(string $method, array $params): array
    {
        try {
            return $this->agent->call($method, $params, self::SECONDS);
        } catch (AgentException $exception) {
            throw ValidationException::withMessages(['import' => $this->backups->reason($exception)]);
        }
    }
}
