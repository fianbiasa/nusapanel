<?php

namespace App\Services;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\AccountStatus;
use App\Enums\DatabaseStatus;
use App\Models\Database;
use App\Models\DatabaseRemoteHost;
use App\Models\Setting;
use App\Models\User;
use App\Support\DatabaseHost;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * What an account does with its databases besides creating them: let its
 * database users connect from other hosts, download a database as a dump,
 * run an SQL file in one, and check, repair or optimize its tables. The
 * administrator decides whether MariaDB answers from outside at all.
 */
class DatabaseToolsService
{
    public const REMOTE_ACCESS_SETTING = 'database.remote_access';

    /**
     * How long a dump, a check or changing where MariaDB listens may take,
     * in seconds.
     */
    private const LONG_TIMEOUT = 600;

    public function __construct(
        private DatabaseService $databases,
        private TaskDispatcher $tasks,
        private AgentClient $agent,
        private AuditLogger $audit,
    ) {}

    /**
     * Whether MariaDB answers on every address, for the hosts accounts
     * allow.
     */
    public function remoteAccessEnabled(): bool
    {
        return Setting::query()->where('key', self::REMOTE_ACCESS_SETTING)->value('value') === true;
    }

    /**
     * Let MariaDB answer on every address, or only on the server again. It
     * restarts, so websites lose their database for a moment.
     *
     * @throws ValidationException
     */
    public function setRemoteAccess(bool $enabled, User $actor): void
    {
        try {
            $this->agent->call('db.set_remote_access', ['enabled' => $enabled], self::LONG_TIMEOUT);
        } catch (AgentException $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'enabled' => __('The database server could not be changed. Try again in a moment.'),
            ]);
        }

        DB::transaction(function () use ($enabled, $actor): void {
            Setting::query()->updateOrCreate(['key' => self::REMOTE_ACCESS_SETTING], ['value' => $enabled]);

            $this->audit->record('server.database_remote_access_changed', null, ['enabled' => $enabled], $actor);
        });
    }

    /**
     * Let the account's database users connect from host too (typed in any
     * form {@see DatabaseHost} understands).
     *
     * @throws ValidationException
     */
    public function addRemoteHost(User $account, string $host, User $actor): DatabaseRemoteHost
    {
        $this->ensureAccountIsActive($account, 'host');

        $normalized = DatabaseHost::normalize($host);

        if ($normalized === null) {
            throw ValidationException::withMessages([
                'host' => __('Enter an IP address, a network like 192.168.1.0/24, a prefix like 192.168.1.% or % for any host.'),
            ]);
        }

        try {
            return DB::transaction(function () use ($account, $normalized, $actor): DatabaseRemoteHost {
                $locked = User::query()->lockForUpdate()->findOrFail($account->id);

                if ($locked->databaseRemoteHosts()->count() >= DatabaseHost::MAX_PER_ACCOUNT) {
                    throw ValidationException::withMessages([
                        'host' => __('An account can allow at most :limit hosts.', ['limit' => DatabaseHost::MAX_PER_ACCOUNT]),
                    ]);
                }

                $remoteHost = $locked->databaseRemoteHosts()->create(['host' => $normalized]);

                $this->databases->reapplyUsers($locked, $actor);

                $this->audit->record('database.remote_host_added', $remoteHost, [
                    'host' => $normalized,
                    'username' => $locked->username,
                ], $actor);

                return $remoteHost;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'host' => __('This host is allowed already.'),
            ]);
        }
    }

    /**
     * Stop letting the account's database users connect from a host.
     */
    public function removeRemoteHost(DatabaseRemoteHost $remoteHost, User $actor): void
    {
        $account = $remoteHost->account;

        DB::transaction(function () use ($remoteHost, $account, $actor): void {
            $remoteHost->delete();

            $this->databases->reapplyUsers($account, $actor);

            $this->audit->record('database.remote_host_removed', $remoteHost, [
                'host' => $remoteHost->host,
                'username' => $account->username,
            ], $actor);
        });
    }

    /**
     * Have the agent write a gzipped dump of the database to the transfer
     * folder, for the browser to download.
     *
     * @return array{file: string, name: string}
     *
     * @throws ValidationException
     */
    public function export(Database $database, User $actor): array
    {
        $this->ensureReady($database, 'database');

        $transfer = Str::random(48);

        try {
            $this->agent->call('db.export', [
                'username' => $database->account->username,
                'database' => $database->name,
                'transfer' => $transfer,
            ], self::LONG_TIMEOUT);
        } catch (AgentException $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'database' => __('The database could not be exported. Try again in a moment.'),
            ]);
        }

        $this->audit->record('database.exported', $database, [
            'database' => $database->name,
            'username' => $database->account->username,
        ], $actor);

        return [
            'file' => FileManagerService::transferPath($transfer.'.download'),
            'name' => $database->name.'-'.now()->format('Ymd-His').'.sql.gz',
        ];
    }

    /**
     * Queue running the SQL file at path (in the account's home, plain or
     * gzip) in the database, as the account; with remove, the file goes
     * afterwards (one uploaded only for the import).
     *
     * @throws ValidationException
     */
    public function import(Database $database, string $path, User $actor, bool $remove = false): void
    {
        $this->ensureReady($database, 'file');

        if ($database->import_status === 'running') {
            throw ValidationException::withMessages([
                'file' => __('An import into this database is still running.'),
            ]);
        }

        $account = $database->account;

        DB::transaction(function () use ($database, $path, $remove, $account, $actor): void {
            $database->forceFill(['import_status' => 'running', 'import_error' => null])->save();

            $this->tasks->dispatch('db.import', [
                'username' => $account->username,
                'database' => $database->name,
                'path' => $path,
                'remove' => $remove,
            ], $account, $actor);

            $this->audit->record('database.imported', $database, [
                'database' => $database->name,
                'username' => $account->username,
            ], $actor);
        });
    }

    /**
     * Check, repair, optimize or analyze the tables of the database and
     * return what MariaDB reported.
     *
     * @return list<string>
     *
     * @throws ValidationException
     */
    public function maintain(Database $database, string $operation, User $actor): array
    {
        $this->ensureReady($database, 'operation');

        try {
            $result = $this->agent->call('db.maintain', [
                'username' => $database->account->username,
                'database' => $database->name,
                'operation' => $operation,
            ], self::LONG_TIMEOUT);
        } catch (AgentException $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'operation' => __('The tables could not be checked. Try again in a moment.'),
            ]);
        }

        $this->audit->record('database.maintained', $database, [
            'database' => $database->name,
            'operation' => $operation,
            'username' => $database->account->username,
        ], $actor);

        return array_values(array_filter((array) ($result['lines'] ?? []), is_string(...)));
    }

    /**
     * @throws ValidationException
     */
    private function ensureReady(Database $database, string $errorKey): void
    {
        $this->ensureAccountIsActive($database->account, $errorKey);

        if ($database->status !== DatabaseStatus::Active) {
            throw ValidationException::withMessages([
                $errorKey => __('Wait until the server has finished the previous change.'),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function ensureAccountIsActive(User $account, string $errorKey): void
    {
        if ($account->status !== AccountStatus::Active) {
            throw ValidationException::withMessages([
                $errorKey => __('Databases can only be changed while the account is active.'),
            ]);
        }
    }
}
