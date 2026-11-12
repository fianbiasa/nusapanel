<?php

namespace App\Services;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\AccountStatus;
use App\Enums\DatabaseStatus;
use App\Enums\Role;
use App\Listeners\ApplyDatabaseTaskResult;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\User;
use App\Support\AccountLimits;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds and removes the MariaDB databases and users of hosting accounts.
 * The panel record is updated right away and the change on the server is
 * queued for the agent; {@see ApplyDatabaseTaskResult} applies the results.
 *
 * Databases and users are named <username>_<name>. A user's password only
 * leaves the panel as its mysql_native_password hash, and every change of
 * a user sends its whole state (password and databases), so applying it
 * again is always safe.
 */
class DatabaseService
{
    public function __construct(
        private TaskDispatcher $tasks,
        private AuditLogger $audit,
        private AgentClient $agent,
    ) {}

    /**
     * Add a database to the account, within its package's database limit,
     * and optionally a new user with access to it.
     *
     * @param  string  $name  The part after "<username>_", already validated.
     * @param  array{name: string, password: string}|null  $newUser  A user to create along with it.
     *
     * @throws ValidationException
     */
    public function createDatabase(User $account, string $name, User $actor, ?array $newUser = null): Database
    {
        $this->ensureAccountIsActive($account, 'name');

        try {
            return DB::transaction(function () use ($account, $name, $actor, $newUser): Database {
                $this->ensureDatabaseLimitIsNotReached($account, $actor);

                $database = $account->databases()->make(['name' => $account->username.'_'.$name]);
                $database->forceFill(['status' => DatabaseStatus::Pending])->save();

                $this->tasks->dispatch('db.create_database', [
                    'username' => $account->username,
                    'database' => $database->name,
                ], $account, $actor);

                $this->audit->record('database.created', $database, [
                    'database' => $database->name,
                    'username' => $account->username,
                ], $actor);

                if ($newUser !== null) {
                    $this->addUser($account, $newUser['name'], $newUser['password'], [$database], $actor);
                }

                return $database;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => __('This name is already in use.'),
            ]);
        }
    }

    /**
     * Drop a database and everyone's access to it. The panel record goes
     * once the agent reports success.
     *
     * @throws ValidationException
     */
    public function deleteDatabase(Database $database, User $actor): void
    {
        $this->ensureSettled($database->status, 'database');

        $account = $database->account;

        DB::transaction(function () use ($database, $account, $actor): void {
            $database->forceFill(['status' => DatabaseStatus::Deleting])->save();

            $this->tasks->dispatch('db.delete_database', [
                'username' => $account->username,
                'database' => $database->name,
            ], $account, $actor);

            $this->audit->record('database.deleted', $database, [
                'database' => $database->name,
                'username' => $account->username,
            ], $actor);
        });
    }

    /**
     * Add a user to the account with access to the given databases of it.
     *
     * @param  string  $name  The part after "<username>_", already validated.
     * @param  list<int>  $databaseIds
     *
     * @throws ValidationException
     */
    public function createUser(User $account, string $name, string $password, array $databaseIds, User $actor): DatabaseUser
    {
        $this->ensureAccountIsActive($account, 'name');

        $databases = $this->accountDatabases($account, $databaseIds);

        try {
            return DB::transaction(fn (): DatabaseUser => $this->addUser($account, $name, $password, $databases, $actor));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => __('This name is already in use.'),
            ]);
        }
    }

    /**
     * Give a user a new password (when one is given) and access to exactly
     * the given databases of its account.
     *
     * @param  list<int>  $databaseIds
     *
     * @throws ValidationException
     */
    public function updateUser(DatabaseUser $user, ?string $password, array $databaseIds, User $actor): void
    {
        $this->ensureSettled($user->status, 'databases');

        $account = $user->account;
        $this->ensureAccountIsActive($account, 'databases');

        $databases = $this->accountDatabases($account, $databaseIds);

        DB::transaction(function () use ($user, $password, $databases, $account, $actor): void {
            $before = $user->databases()->orderBy('name')->pluck('name')->all();

            if ($password !== null) {
                $user->forceFill(['password_hash' => DatabaseUser::hashPassword($password)]);
            }

            $user->forceFill(['status' => DatabaseStatus::Pending])->save();
            $user->databases()->sync(array_map(fn (Database $database): int => $database->id, $databases));

            $this->dispatchApplyUser($user, $account, $actor);

            $this->audit->record('database_user.updated', $user, [
                'user' => $user->name,
                'password_changed' => $password !== null,
                'databases' => ['from' => $before, 'to' => array_map(fn (Database $database): string => $database->name, $databases)],
                'username' => $account->username,
            ], $actor);
        });
    }

    /**
     * Drop a user. The panel record goes once the agent reports success.
     *
     * @throws ValidationException
     */
    public function deleteUser(DatabaseUser $user, User $actor): void
    {
        $this->ensureSettled($user->status, 'user');

        $account = $user->account;

        DB::transaction(function () use ($user, $account, $actor): void {
            $user->forceFill(['status' => DatabaseStatus::Deleting])->save();

            $this->tasks->dispatch('db.delete_user', [
                'username' => $account->username,
                'user' => $user->name,
            ], $account, $actor);

            $this->audit->record('database_user.deleted', $user, [
                'user' => $user->name,
                'username' => $account->username,
            ], $actor);
        });
    }

    /**
     * Store the size of every database as the agent measures it now.
     *
     * @return int The number of databases measured.
     *
     * @throws AgentException When the agent is unreachable or reports an error; nothing is stored then.
     */
    public function collectSizes(): int
    {
        $report = $this->agent->call('db.report', [], (int) config('agent.timeout'));
        $measuredAt = now();
        $measured = 0;

        foreach ($report['databases'] ?? [] as $row) {
            if (! is_array($row) || ! is_string($row['name'] ?? null) || ! is_int($row['size_bytes'] ?? null)) {
                continue;
            }

            $measured += Database::query()
                ->where('name', $row['name'])
                ->update(['size_bytes' => max(0, $row['size_bytes']), 'measured_at' => $measuredAt]);
        }

        return $measured;
    }

    /**
     * Save a new user and queue it.
     *
     * @param  list<Database>  $databases
     */
    private function addUser(User $account, string $name, string $password, array $databases, User $actor): DatabaseUser
    {
        $user = $account->databaseUsers()->make(['name' => $account->username.'_'.$name]);
        $user->forceFill([
            'password_hash' => DatabaseUser::hashPassword($password),
            'status' => DatabaseStatus::Pending,
        ])->save();

        $user->databases()->attach(array_map(fn (Database $database): int => $database->id, $databases));

        $this->dispatchApplyUser($user, $account, $actor);

        $this->audit->record('database_user.created', $user, [
            'user' => $user->name,
            'databases' => array_map(fn (Database $database): string => $database->name, $databases),
            'username' => $account->username,
        ], $actor);

        return $user;
    }

    /**
     * Queue the whole state of a user: its password and the databases it
     * may use, leaving out those being dropped.
     */
    private function dispatchApplyUser(DatabaseUser $user, User $account, ?User $actor): void
    {
        $databases = $user->databases()
            ->where('status', '!=', DatabaseStatus::Deleting)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $this->tasks->dispatch('db.apply_user', [
            'username' => $account->username,
            'user' => $user->name,
            'password_hash' => $user->password_hash,
            'databases' => $databases,
            'remote_hosts' => $account->databaseRemoteHosts()->orderBy('host')->pluck('host')->all(),
        ], $account, $actor);
    }

    /**
     * Queue the whole state of every user of the account again, e.g. after
     * the hosts they may connect from changed.
     */
    public function reapplyUsers(User $account, ?User $actor): void
    {
        $account->databaseUsers()
            ->where('status', '!=', DatabaseStatus::Deleting)
            ->orderBy('id')
            ->each(fn (DatabaseUser $user) => $this->dispatchApplyUser($user, $account, $actor));
    }

    /**
     * The given databases of the account, refusing any other.
     *
     * @param  list<int>  $ids
     * @return list<Database>
     *
     * @throws ValidationException
     */
    private function accountDatabases(User $account, array $ids): array
    {
        $databases = $account->databases()
            ->whereIn('id', $ids)
            ->where('status', '!=', DatabaseStatus::Deleting)
            ->orderBy('name')
            ->get();

        if ($databases->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'databases' => __('Choose databases of this account.'),
            ]);
        }

        return array_values($databases->all());
    }

    /**
     * @throws ValidationException
     */
    private function ensureAccountIsActive(User $account, string $errorKey): void
    {
        if (! $account->hasRole(Role::User) || $account->status !== AccountStatus::Active) {
            throw ValidationException::withMessages([
                $errorKey => __('Databases can only be changed while the account is active.'),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function ensureSettled(DatabaseStatus $status, string $errorKey): void
    {
        if (! $status->isSettled()) {
            throw ValidationException::withMessages([
                $errorKey => __('Wait until the server has finished the previous change.'),
            ]);
        }
    }

    /**
     * Refuse a database the account's package has no room for. The
     * account's row stays locked until the database is saved, so two
     * requests cannot both take the last free place.
     *
     * @throws ValidationException
     */
    private function ensureDatabaseLimitIsNotReached(User $account, User $actor): void
    {
        $locked = User::query()->with('package')->lockForUpdate()->findOrFail($account->id);
        $limits = AccountLimits::for($locked);

        if (! $limits->isReached('databases')) {
            return;
        }

        $replace = ['limit' => $limits->limit('databases')];

        throw ValidationException::withMessages([
            'name' => $actor->is($account)
                ? __('Your package allows :limit databases. Remove a database or ask your provider for a bigger package.', $replace)
                : __("This account's package allows :limit databases.", $replace),
        ]);
    }
}
