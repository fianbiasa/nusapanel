<?php

namespace App\Services;

use App\Enums\DomainType;
use App\Enums\PointerMode;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\AccountRestore;
use App\Models\Domain;
use App\Models\ServerBackup;
use App\Models\Task;
use App\Models\User;
use App\Rules\CronSchedule;
use App\Rules\ValidDomain;
use App\Rules\ValidMailAddresses;
use App\Support\MailAddress;
use App\Support\NotificationEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Deleted hosting accounts made again from a backup the server keeps, with
 * the username they had. The account and its websites are created first,
 * from the panel's settings in the backup. Once the account exists on the
 * server, its databases with their users, its mailboxes, forwarders and
 * cron jobs come back, and then its files, mail and the data of its
 * databases, in a task that waits for everything before it.
 */
class AccountRestoreService
{
    public function __construct(
        private ServerBackupService $serverBackups,
        private BackupService $backups,
        private AccountService $accounts,
        private DomainService $domains,
        private MailForwarderService $forwarders,
        private CronJobService $cron,
        private PhpVersionService $phpVersions,
        private TaskDispatcher $tasks,
        private AuditLogger $audit,
        private NotificationService $notifications,
    ) {}

    /**
     * What the settings of a backup say about the account itself. Backups
     * made before they held it say nothing.
     *
     * @param  array<string, mixed>  $settings
     * @return array{name: string|null, email: string|null, password_hash: string|null, package: string|null}
     */
    public function savedAccount(array $settings): array
    {
        $account = is_array($settings['account'] ?? null) ? $settings['account'] : [];
        $text = fn (string $key): ?string => is_string($account[$key] ?? null) && $account[$key] !== '' ? $account[$key] : null;
        $hash = $text('password_hash');

        return [
            'name' => $text('name'),
            'email' => $text('email'),
            'password_hash' => $hash !== null && password_get_info($hash)['algo'] !== null ? $hash : null,
            'package' => $text('package'),
        ];
    }

    /**
     * The administrator or reseller a restored account belongs to: the one
     * it had, while it may still own accounts and the actor acts for it,
     * or else the actor.
     */
    public function ownerFor(ServerBackup $backup, User $actor): User
    {
        $owner = $backup->owner;

        if ($owner !== null && $owner->hasRole(Role::Admin, Role::Reseller) && ($actor->isAdmin() || $owner->is($actor))) {
            return $owner;
        }

        return $actor;
    }

    /**
     * Make the deleted account of backup again: the account with its name,
     * email and password (the backup's, unless new ones are given) on the
     * package chosen, and its websites. What does not fit, such as a
     * domain another account took meanwhile, is left out and reported.
     *
     * @param  array{email?: string|null, password?: string|null, package_id?: int|null}  $data
     *
     * @throws ValidationException
     */
    public function restore(ServerBackup $backup, array $data, User $actor): User
    {
        if ($backup->account !== null) {
            throw ValidationException::withMessages(['backup' => __('The account of this backup still exists; restore the backup into it instead.')]);
        }

        if (User::query()->where('username', $backup->username)->exists()) {
            throw ValidationException::withMessages(['backup' => __('The username :username is in use again, so the account cannot be made again.', ['username' => $backup->username])]);
        }

        $described = $this->serverBackups->read($backup, $backup->username);
        $saved = $this->savedAccount($described['settings']);
        $email = ($data['email'] ?? null) ?: $saved['email'];
        $password = ($data['password'] ?? null) ?: $saved['password_hash'];

        if ($email === null) {
            throw ValidationException::withMessages(['email' => __('The backup does not hold the account\'s email address; enter one.')]);
        }

        if ($password === null) {
            throw ValidationException::withMessages(['password' => __('The backup does not hold the account\'s password; enter a new one.')]);
        }

        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => __('Another account uses this email address.')]);
        }

        $owner = $this->ownerFor($backup, $actor);
        $domains = $this->domainsOf($described['settings']);
        $primary = collect($domains)->first(fn (array $domain): bool => $domain['is_primary'] && $domain['type'] === DomainType::Domain->value)
            ?? collect($domains)->firstWhere('type', DomainType::Domain->value);

        return DB::transaction(function () use ($backup, $data, $actor, $owner, $saved, $email, $password, $domains, $primary): User {
            $account = $this->accounts->create([
                'username' => $backup->username,
                'name' => $saved['name'] ?? $backup->username,
                'email' => $email,
                'password' => $password,
                'package_id' => $data['package_id'] ?? null,
                'domain' => $primary['name'] ?? null,
            ], $actor, $owner);

            $restore = new AccountRestore;
            $restore->forceFill([
                'server_backup_id' => $backup->id,
                'user_id' => $account->id,
                'actor_id' => $actor->id,
                'status' => AccountRestore::STATUS_WAITING,
                'skipped' => $this->bringBackDomains($account, $domains, $primary['name'] ?? null, $actor),
            ])->save();

            $this->audit->record('backup.account_restored', $account, ['name' => $backup->name, 'username' => $account->username], $actor);

            return $account;
        });
    }

    /**
     * Go on with the account's restore once the account exists on the
     * server: its databases, mailboxes, forwarders and cron jobs, then the
     * data. A failure is recorded and the administrators hear about it.
     */
    public function resume(User $account): void
    {
        $restore = $this->waiting($account);

        if ($restore === null) {
            return;
        }

        $actor = $restore->actor ?? $account->owner ?? $account;

        try {
            $backup = $restore->backup ?? throw ValidationException::withMessages(['backup' => __('The backup was deleted before the account was ready.')]);
            $described = $this->serverBackups->read($backup, $account->username);

            DB::transaction(function () use ($account, $restore, $backup, $described, $actor): void {
                [$files, $email] = $this->backups->prepareRestore($account, $described, $described['files'], $described['email'], $described['databases'], $actor);

                $skipped = [
                    ...($restore->skipped ?? []),
                    ...$this->bringBackForwarders($account, $described['settings'], $actor),
                    ...$this->bringBackCronJobs($account, $described['settings'], $actor),
                ];

                $this->tasks->dispatch('backup.server_restore', [
                    'username' => $account->username,
                    'name' => $backup->name,
                    'files' => $files,
                    'email' => $email,
                    'databases' => $described['databases'],
                ], $account, $actor);

                $restore->forceFill(['status' => AccountRestore::STATUS_RESTORING, 'skipped' => $skipped])->save();
            });
        } catch (ValidationException $exception) {
            $this->fail($restore, (string) collect($exception->errors())->flatten()->first());
        }
    }

    /**
     * How the account made again from server backup went, for the pages
     * that started it (moves and imports): still restoring, completed or
     * failed with why, and what was left out. Null when nothing was made.
     *
     * @return array{status: string, error: string|null, skipped: list<string>}|null
     */
    public function progressOf(int $serverBackupId): ?array
    {
        $restore = AccountRestore::query()->where('server_backup_id', $serverBackupId)->latest('id')->first();

        if ($restore === null) {
            return null;
        }

        $task = $restore->status === AccountRestore::STATUS_RESTORING
            ? Task::query()->where('user_id', $restore->user_id)->where('action', 'backup.server_restore')->latest('id')->first()
            : null;

        $status = match (true) {
            $restore->status === AccountRestore::STATUS_FAILED, $task?->status === TaskStatus::Failed => 'failed',
            $task?->status === TaskStatus::Success => 'completed',
            default => 'restoring',
        };

        return [
            'status' => $status,
            'error' => $restore->error ?? ($status === 'failed' ? $task?->error : null),
            'skipped' => $restore->skipped ?? [],
        ];
    }

    /**
     * The account could not be created on the server, so nothing else can
     * come back.
     */
    public function accountFailed(User $account): void
    {
        $restore = $this->waiting($account);

        if ($restore !== null) {
            $this->fail($restore, __('The account could not be created on the server.'));
        }
    }

    private function waiting(User $account): ?AccountRestore
    {
        return AccountRestore::query()
            ->where('user_id', $account->id)
            ->where('status', AccountRestore::STATUS_WAITING)
            ->latest('id')
            ->first();
    }

    private function fail(AccountRestore $restore, string $message): void
    {
        $restore->forceFill(['status' => AccountRestore::STATUS_FAILED, 'error' => $message])->save();

        $this->notifications->toAdmins(
            NotificationEvents::BACKUP,
            __('Account :username could not be restored', ['username' => $restore->account->username]),
            $message,
            'account-restore:'.$restore->id,
        );
    }

    /**
     * The well-formed websites in a backup's settings.
     *
     * @param  array<string, mixed>  $settings
     * @return list<array{name: string, type: string, parent: string|null, pointer_mode: string|null, php_version: string|null, is_primary: bool}>
     */
    private function domainsOf(array $settings): array
    {
        $domains = [];

        foreach ((array) ($settings['domains'] ?? []) as $domain) {
            if (! is_array($domain) || ! is_string($domain['name'] ?? null) || ! ValidDomain::isWellFormed($domain['name']) || DomainType::tryFrom((string) ($domain['type'] ?? '')) === null) {
                continue;
            }

            $domains[] = [
                'name' => $domain['name'],
                'type' => (string) $domain['type'],
                'parent' => is_string($domain['parent'] ?? null) ? $domain['parent'] : null,
                'pointer_mode' => is_string($domain['pointer_mode'] ?? null) ? $domain['pointer_mode'] : null,
                'php_version' => is_string($domain['php_version'] ?? null) ? $domain['php_version'] : null,
                'is_primary' => ($domain['is_primary'] ?? false) === true,
            ];
        }

        return $domains;
    }

    /**
     * Add the websites of the backup to the new account: domains, then
     * their subdomains and pointers, with the PHP version each had. The
     * primary domain was added with the account. Returns what was left out.
     *
     * @param  list<array{name: string, type: string, parent: string|null, pointer_mode: string|null, php_version: string|null, is_primary: bool}>  $domains
     * @return list<string>
     */
    private function bringBackDomains(User $account, array $domains, ?string $primary, User $actor): array
    {
        $order = [DomainType::Domain->value => 0, DomainType::Subdomain->value => 1, DomainType::Pointer->value => 2];
        usort($domains, fn (array $a, array $b): int => $order[$a['type']] <=> $order[$b['type']]);

        $versions = $this->phpVersions->available();
        $skipped = [];

        foreach ($domains as $domain) {
            try {
                $made = match (true) {
                    $domain['name'] === $primary => $account->domains()->where('name', $primary)->firstOrFail(),
                    $domain['type'] === DomainType::Domain->value => $this->domains->create($account, $domain['name'], $actor),
                    default => $this->addChild($account, $domain, $actor),
                };
            } catch (ValidationException $exception) {
                $skipped[] = $domain['name'].': '.collect($exception->errors())->flatten()->first();

                continue;
            }

            if ($made->type !== DomainType::Pointer && in_array($domain['php_version'], $versions, true)) {
                $made->forceFill(['php_version' => $domain['php_version']])->save();
            }
        }

        return $skipped;
    }

    /**
     * Add a subdomain or pointer of the backup to its domain.
     *
     * @param  array{name: string, type: string, parent: string|null, pointer_mode: string|null}  $domain
     *
     * @throws ValidationException
     */
    private function addChild(User $account, array $domain, User $actor): Domain
    {
        $parent = $domain['parent'] === null ? null : $account->domains()->where('name', $domain['parent'])->where('type', DomainType::Domain)->first();

        if ($parent === null) {
            throw ValidationException::withMessages(['name' => __('Its domain did not come back.')]);
        }

        if ($domain['type'] === DomainType::Pointer->value) {
            return $this->domains->createPointer($parent, $domain['name'], PointerMode::tryFrom((string) $domain['pointer_mode']) ?? PointerMode::Alias, $actor);
        }

        $label = substr($domain['name'], 0, -strlen('.'.$parent->name));

        if (! str_ends_with($domain['name'], '.'.$parent->name) || preg_match(ValidDomain::LABEL_PATTERN, $label) !== 1) {
            throw ValidationException::withMessages(['name' => __('It is not a subdomain of :parent.', ['parent' => $parent->name])]);
        }

        return $this->domains->createSubdomain($parent, $label, $actor);
    }

    /**
     * Add the forwarders of the backup on the domains the account has
     * again. Returns what was left out.
     *
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    private function bringBackForwarders(User $account, array $settings, User $actor): array
    {
        $domains = $account->domains()->where('type', DomainType::Domain)->pluck('id', 'name');
        $skipped = [];

        foreach ((array) ($settings['forwarders'] ?? []) as $forwarder) {
            if (! is_array($forwarder) || ! is_string($forwarder['domain'] ?? null) || ! is_string($forwarder['local_part'] ?? null)) {
                continue;
            }

            // Backups made before they held a list keep the addresses as
            // Postfix reads them, separated by commas.
            if (is_string($forwarder['destinations'] ?? null)) {
                $forwarder['destinations'] = array_values(array_filter(explode(',', $forwarder['destinations'])));
            }

            $address = $forwarder['local_part'].'@'.$forwarder['domain'];

            $validator = Validator::make($forwarder, [
                'local_part' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_+-]+(\.[a-z0-9_+-]+)*$/D'],
                'destinations' => ['required', 'array', 'min:1', 'max:'.MailAddress::MAX_DESTINATIONS, new ValidMailAddresses],
            ]);

            if ($validator->fails()) {
                $skipped[] = $address.': '.$validator->errors()->first();

                continue;
            }

            if (! isset($domains[$forwarder['domain']])) {
                $skipped[] = $address.': '.__('Its domain did not come back.');

                continue;
            }

            try {
                $this->forwarders->create($account, $domains[$forwarder['domain']], $forwarder['local_part'], array_values($forwarder['destinations']), $actor);
            } catch (ValidationException $exception) {
                $skipped[] = $address.': '.collect($exception->errors())->flatten()->first();
            }
        }

        return $skipped;
    }

    /**
     * Add the cron jobs of the backup, paused ones paused. Returns what was
     * left out.
     *
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    private function bringBackCronJobs(User $account, array $settings, User $actor): array
    {
        $skipped = [];

        foreach ((array) ($settings['cron_jobs'] ?? []) as $job) {
            if (! is_array($job)) {
                continue;
            }

            $validator = Validator::make($job, [
                'schedule' => ['required', 'string', 'max:100', new CronSchedule],
                'command' => ['required', 'string', 'max:1024', 'not_regex:/[\r\n\x00]/'],
                'description' => ['nullable', 'string', 'max:100'],
            ]);

            if ($validator->fails()) {
                $skipped[] = __('A cron job').': '.$validator->errors()->first();

                continue;
            }

            try {
                $made = $this->cron->create($account, $job['schedule'], $job['command'], $job['description'] ?? null, $actor);

                if (($job['enabled'] ?? true) === false) {
                    $this->cron->update($made, $made->schedule, $made->command, $made->description, false, $actor);
                }
            } catch (ValidationException $exception) {
                $skipped[] = $job['schedule'].' '.$job['command'].': '.collect($exception->errors())->flatten()->first();
            }
        }

        return $skipped;
    }
}
