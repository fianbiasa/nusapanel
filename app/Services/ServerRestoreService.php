<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\BackupRunStatus;
use App\Enums\Role;
use App\Enums\ServerBackupStatus;
use App\Enums\TaskStatus;
use App\Models\AccountRestore;
use App\Models\BackupRun;
use App\Models\ServerBackup;
use App\Models\SystemBackup;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A panel brought back from a backup of itself on a new server (setup.sh
 * --restore-system). Its database says which accounts, websites,
 * databases and mailboxes there are; this makes them on the server again,
 * empty: the Linux users, the MariaDB databases with their users and
 * passwords, then the configuration of every website, quota, crontab, FTP
 * folder, DNS zone and mail domain (as "rayxo rebuild" does). The data of
 * the accounts comes back afterwards from their own backups.
 */
class ServerRestoreService
{
    public function __construct(
        private TaskDispatcher $tasks,
        private DatabaseService $databases,
        private RebuildService $rebuild,
        private SshService $ssh,
    ) {}

    /**
     * Queue it all. What was queued or running when the backup was made
     * never finishes on this server, so it is closed first; the backup of
     * the panel restored from (named systemBackup) was itself still being
     * made then, and is done.
     *
     * @return array{accounts: int, databases: int, websites: int, quotas: int, crontabs: int, ftp_folders: int, dns_zones: int, mail_domains: int}
     */
    public function recreate(?string $systemBackup = null): array
    {
        if ($systemBackup !== null) {
            SystemBackup::query()->where('name', $systemBackup)
                ->update(['status' => ServerBackupStatus::Completed, 'finished_at' => now()]);
        }

        $this->closeWhatWasGoing();

        $accounts = User::query()
            ->where('role', Role::User)
            ->whereIn('status', [AccountStatus::Active, AccountStatus::Suspended, AccountStatus::Pending])
            ->orderBy('id')
            ->get();

        foreach ($accounts as $account) {
            $this->tasks->dispatch('account.create', ['username' => $account->username], $account);
        }

        // Accounts that were being deleted are deleted here too.
        User::query()->where('role', Role::User)->where('status', AccountStatus::Deleting)->each(
            fn (User $account) => $this->tasks->dispatch('account.delete', ['username' => $account->username], $account),
        );

        $databases = $this->databases->reapplyAll();
        $rebuilt = $this->rebuild->queueAll();
        $this->ssh->reapply(User::query(), null);

        foreach ($accounts->where('status', AccountStatus::Suspended) as $account) {
            $this->tasks->dispatch('account.suspend', ['username' => $account->username], $account);
        }

        return ['accounts' => $accounts->count(), 'databases' => $databases, ...$rebuilt];
    }

    private function closeWhatWasGoing(): void
    {
        $note = __('Stopped: the panel was restored from a backup on another server.');

        DB::table('jobs')->delete();

        Task::query()->whereIn('status', [TaskStatus::Queued, TaskStatus::Running])
            ->update(['status' => TaskStatus::Failed, 'error' => $note, 'finished_at' => now()]);

        foreach ([ServerBackup::class, SystemBackup::class] as $model) {
            $model::query()->whereIn('status', [ServerBackupStatus::Queued, ServerBackupStatus::Running])
                ->update(['status' => ServerBackupStatus::Failed, 'error' => $note, 'finished_at' => now()]);
        }

        BackupRun::query()->where('status', BackupRunStatus::Running)
            ->update(['status' => BackupRunStatus::Completed, 'message' => $note, 'finished_at' => now()]);

        AccountRestore::query()->whereIn('status', [AccountRestore::STATUS_WAITING])
            ->update(['status' => AccountRestore::STATUS_FAILED, 'error' => $note]);
    }
}
