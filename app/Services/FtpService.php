<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Enums\FtpAccountStatus;
use App\Enums\Role;
use App\Listeners\ApplyFtpTaskResult;
use App\Models\Domain;
use App\Models\FtpAccount;
use App\Models\User;
use App\Support\AccountLimits;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds, changes and removes the FTP logins of hosting accounts.
 *
 * Pure-FTPd reads the logins straight from the panel's database (the
 * v_ftp_users view), so a new password or a removed login takes effect at
 * the next sign-in. Only a login's folder needs the agent, which creates it
 * as the account's; the login is ready once that is done
 * ({@see ApplyFtpTaskResult}).
 */
class FtpService
{
    /**
     * The longest login Pure-FTPd and the database accept.
     */
    public const MAX_LOGIN = 128;

    public function __construct(
        private TaskDispatcher $tasks,
        private AuditLogger $audit,
    ) {}

    /**
     * Add the login <name>@<domain> to the account, shut in $directory of
     * its home, within its package's FTP limit.
     *
     * @param  string  $name  Already validated.
     * @param  string  $directory  Relative to the home, already validated; "" is the whole home.
     *
     * @throws ValidationException
     */
    public function create(User $account, string $name, int $domainId, string $directory, string $password, User $actor): FtpAccount
    {
        $this->ensureAccountIsActive($account, 'name');

        $domain = $this->accountDomain($account, $domainId);
        $login = $name.'@'.$domain->name;

        if (strlen($login) > self::MAX_LOGIN) {
            throw ValidationException::withMessages([
                'name' => __('The login may have at most :count characters.', ['count' => self::MAX_LOGIN]),
            ]);
        }

        try {
            return DB::transaction(function () use ($account, $domain, $login, $directory, $password, $actor): FtpAccount {
                $this->ensureLimitIsNotReached($account, $actor);

                $ftpAccount = $account->ftpAccounts()->make(['login' => $login, 'directory' => $directory]);
                $ftpAccount->forceFill([
                    'domain_id' => $domain->id,
                    'password_hash' => FtpAccount::hashPassword($password),
                    'status' => FtpAccountStatus::Pending,
                ])->save();

                $this->prepareFolder($ftpAccount, $account, $actor);

                $this->audit->record('ftp_account.created', $ftpAccount, [
                    'login' => $login,
                    'directory' => $ftpAccount->displayDirectory(),
                ], $actor);

                return $ftpAccount;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => __('This name is already in use.'),
            ]);
        }
    }

    /**
     * Give the login a new password (null keeps it) and folder. A new
     * folder, or one that failed before, is prepared again by the agent.
     *
     * @throws ValidationException
     */
    public function update(FtpAccount $ftpAccount, ?string $password, string $directory, User $actor): void
    {
        $account = $ftpAccount->account;

        $this->ensureAccountIsActive($account, 'directory');

        if (! $ftpAccount->status->isSettled()) {
            throw ValidationException::withMessages([
                'directory' => __('Wait until the server has finished the previous change.'),
            ]);
        }

        DB::transaction(function () use ($ftpAccount, $account, $password, $directory, $actor): void {
            $changes = [];

            if ($password !== null) {
                $ftpAccount->password_hash = FtpAccount::hashPassword($password);
                $changes['password'] = 'changed';
            }

            if ($directory !== $ftpAccount->directory || $ftpAccount->status === FtpAccountStatus::Failed) {
                $ftpAccount->directory = $directory;
                $ftpAccount->status = FtpAccountStatus::Pending;
                $changes['directory'] = $ftpAccount->displayDirectory();

                $this->prepareFolder($ftpAccount, $account, $actor);
            }

            $ftpAccount->save();

            $this->audit->record('ftp_account.updated', $ftpAccount, ['login' => $ftpAccount->login, ...$changes], $actor);
        });
    }

    /**
     * Remove the login. Its folder and files stay.
     */
    public function delete(FtpAccount $ftpAccount, User $actor): void
    {
        $this->audit->record('ftp_account.deleted', $ftpAccount, ['login' => $ftpAccount->login], $actor);

        $ftpAccount->delete();
    }

    /**
     * The domains and subdomains of the account an FTP login may be named
     * after.
     *
     * @return list<Domain>
     */
    public function loginDomains(User $account): array
    {
        return array_values($account->domains()
            ->whereIn('type', [DomainType::Domain, DomainType::Subdomain])
            ->where('status', '!=', DomainStatus::Deleting)
            ->orderBy('name')
            ->get()
            ->all());
    }

    /**
     * Queue the folder of every FTP login of an active or suspended account
     * again. Used by rayxo:rebuild.
     *
     * @return int The number of logins queued.
     */
    public function reapplyAll(?User $actor = null): int
    {
        $queued = 0;

        FtpAccount::query()
            ->with('account')
            ->whereHas('account', fn ($query) => $query->whereIn('status', [AccountStatus::Active, AccountStatus::Suspended]))
            ->orderBy('id')
            ->each(function (FtpAccount $ftpAccount) use ($actor, &$queued): void {
                $this->prepareFolder($ftpAccount, $ftpAccount->account, $actor);
                $queued++;
            });

        return $queued;
    }

    private function prepareFolder(FtpAccount $ftpAccount, User $account, ?User $actor): void
    {
        $this->tasks->dispatch('ftp.ensure_dir', [
            'username' => $account->username,
            'directory' => $ftpAccount->directory,
        ], $account, $actor);
    }

    /**
     * @throws ValidationException
     */
    private function accountDomain(User $account, int $domainId): Domain
    {
        foreach ($this->loginDomains($account) as $domain) {
            if ($domain->id === $domainId) {
                return $domain;
            }
        }

        throw ValidationException::withMessages([
            'domain_id' => __('Choose a domain of this account.'),
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function ensureAccountIsActive(User $account, string $errorKey): void
    {
        if (! $account->hasRole(Role::User) || $account->status !== AccountStatus::Active) {
            throw ValidationException::withMessages([
                $errorKey => __('FTP accounts can only be changed while the account is active.'),
            ]);
        }
    }

    /**
     * Refuse a login the account's package has no room for. The account's
     * row stays locked until the login is saved, so two requests cannot both
     * take the last free place.
     *
     * @throws ValidationException
     */
    private function ensureLimitIsNotReached(User $account, User $actor): void
    {
        $locked = User::query()->with('package')->lockForUpdate()->findOrFail($account->id);
        $limits = AccountLimits::for($locked);

        if (! $limits->isReached('ftp_accounts')) {
            return;
        }

        $replace = ['limit' => $limits->limit('ftp_accounts')];

        throw ValidationException::withMessages([
            'name' => $actor->is($account)
                ? __('Your package allows :limit FTP accounts. Remove one or ask your provider for a bigger package.', $replace)
                : __("This account's package allows :limit FTP accounts.", $replace),
        ]);
    }
}
