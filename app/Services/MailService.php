<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Enums\MailDomainStatus;
use App\Enums\Role;
use App\Listeners\ApplyMailTaskResult;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\User;
use App\Support\AccountLimits;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds, changes and removes the email accounts of hosting accounts.
 *
 * Postfix and Dovecot read mail domains and mailboxes straight from the
 * panel's database (the v_mail_* views), so a new password or a removed
 * mailbox takes effect at once. A domain's first mailbox has the agent
 * prepare the domain (its mail folder and DKIM key, mail.domain_apply);
 * once that is done the domain takes mail and its DNS zone gets the DKIM,
 * DMARC and MX records ({@see ApplyMailTaskResult}).
 */
class MailService
{
    /**
     * The largest mailbox quota, in megabytes (1 TB).
     */
    public const MAX_QUOTA_MB = 1_048_576;

    /**
     * The DKIM selector of every mail domain.
     */
    public const SELECTOR = 'rayxo';

    public function __construct(
        private TaskDispatcher $tasks,
        private AuditLogger $audit,
        private DnsService $dns,
    ) {}

    /**
     * The domains of the account that can have email: its domains (not
     * subdomains or pointers) that are not being deleted.
     *
     * @return list<Domain>
     */
    public function domainsOf(User $account): array
    {
        return array_values($account->domains()
            ->with('mailDomain')
            ->where('type', DomainType::Domain)
            ->where('status', '!=', DomainStatus::Deleting)
            ->orderBy('name')
            ->get()
            ->all());
    }

    /**
     * Add the mailbox <localPart>@<domain> to the account, within its
     * package's email limit. The domain's mail is set up on its first
     * mailbox.
     *
     * @param  string  $localPart  Already validated.
     * @param  int|null  $quotaMb  Already validated; null is no limit.
     *
     * @throws ValidationException
     */
    public function createMailbox(User $account, int $domainId, string $localPart, string $password, ?int $quotaMb, User $actor): Mailbox
    {
        $this->ensureAccountIsActive($account, 'local_part');

        $domain = collect($this->domainsOf($account))->firstWhere('id', $domainId);

        if (! $domain instanceof Domain) {
            throw ValidationException::withMessages(['domain_id' => __('Choose a domain of this account.')]);
        }

        try {
            return DB::transaction(function () use ($account, $domain, $localPart, $password, $quotaMb, $actor): Mailbox {
                $this->ensureLimitIsNotReached($account, $actor);

                $mailDomain = $this->ensureMailDomain($domain, $account, $actor);

                $mailbox = $mailDomain->mailboxes()->make(['local_part' => $localPart, 'quota_mb' => $quotaMb]);
                $mailbox->forceFill(['password_hash' => Mailbox::hashPassword($password), 'status' => 'active'])->save();

                $this->audit->record('mailbox.created', $mailbox, [
                    'address' => "{$localPart}@{$domain->name}",
                    'quota_mb' => $quotaMb,
                ], $actor);

                return $mailbox;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['local_part' => __('This address is already in use.')]);
        }
    }

    /**
     * Give a mailbox a new password (null keeps it) and quota (null is no
     * limit). Dovecot checks both at the next sign-in and delivery.
     *
     * @throws ValidationException
     */
    public function updateMailbox(Mailbox $mailbox, ?string $password, ?int $quotaMb, User $actor): void
    {
        $domain = $mailbox->mailDomain->domain;

        $this->ensureAccountIsActive($domain->account, 'password');

        $changes = [];

        if ($password !== null) {
            $mailbox->password_hash = Mailbox::hashPassword($password);
            $changes['password'] = 'changed';
        }

        if ($quotaMb !== $mailbox->quota_mb) {
            $mailbox->quota_mb = $quotaMb;
            $changes['quota_mb'] = $quotaMb;
        }

        $mailbox->save();

        $this->audit->record('mailbox.updated', $mailbox, ['address' => $this->address($mailbox), ...$changes], $actor);
    }

    /**
     * Remove a mailbox and delete its mail.
     */
    public function deleteMailbox(Mailbox $mailbox, User $actor): void
    {
        $domain = $mailbox->mailDomain->domain;
        $account = $domain->account;

        DB::transaction(function () use ($mailbox, $domain, $account, $actor): void {
            $this->audit->record('mailbox.deleted', $mailbox, ['address' => $this->address($mailbox)], $actor);

            $mailbox->delete();

            $this->tasks->dispatch('mail.mailbox_remove', [
                'username' => $account->username,
                'domain' => $domain->name,
                'local_part' => $mailbox->local_part,
            ], $account, $actor);
        });
    }

    /**
     * Take mail for a domain the agent has prepared, and put its DKIM,
     * DMARC and MX records into its DNS zone.
     *
     * @param  array<string, mixed>  $output  The result of mail.domain_apply.
     */
    public function completeDomainApply(MailDomain $mailDomain, array $output, ?User $actor): void
    {
        $key = $output['dkim_public_key'] ?? null;

        $mailDomain->forceFill([
            'status' => MailDomainStatus::Active,
            'dkim_public_key' => is_string($key) && preg_match('/^[A-Za-z0-9+\/=]{1,2000}$/', $key) === 1 ? $key : $mailDomain->dkim_public_key,
        ])->save();

        if ($mailDomain->dkim_public_key !== null) {
            $this->dns->applyMailRecords($mailDomain->domain, $mailDomain->dkim_selector, $mailDomain->dkim_public_key, $actor);
        }
    }

    /**
     * Mark a domain whose mail the agent could not prepare; its next
     * mailbox tries again.
     */
    public function failDomainApply(MailDomain $mailDomain): void
    {
        $mailDomain->forceFill(['status' => MailDomainStatus::Failed])->save();
    }

    /**
     * Stop taking mail for a domain that is being deleted: its DKIM key and
     * mail go from the server, its mailboxes from the panel.
     */
    public function removeDomain(Domain $domain, ?User $actor): void
    {
        $mailDomain = $domain->mailDomain;

        if ($mailDomain === null) {
            return;
        }

        $this->tasks->dispatch('mail.domain_remove', [
            'username' => $domain->account->username,
            'domain' => $domain->name,
        ], $domain->account, $actor);

        $mailDomain->delete();
    }

    /**
     * Remove the DKIM keys of every mail domain of an account that is being
     * deleted; its mail goes with its home.
     */
    public function removeAccount(User $account, ?User $actor): void
    {
        MailDomain::query()
            ->with('domain')
            ->whereHas('domain', fn ($query) => $query->where('user_id', $account->id))
            ->orderBy('id')
            ->each(function (MailDomain $mailDomain) use ($account, $actor): void {
                $this->tasks->dispatch('mail.domain_remove', [
                    'username' => $account->username,
                    'domain' => $mailDomain->domain->name,
                ], $account, $actor);
            });
    }

    /**
     * Prepare every mail domain of active and suspended accounts again.
     * Used by rayxo:rebuild.
     *
     * @return int The number of mail domains queued.
     */
    public function reapplyAll(?User $actor = null): int
    {
        $queued = 0;

        MailDomain::query()
            ->with('domain.account')
            ->whereHas('domain.account', fn ($query) => $query
                ->where('role', Role::User)
                ->whereIn('status', [AccountStatus::Active, AccountStatus::Suspended]))
            ->orderBy('id')
            ->each(function (MailDomain $mailDomain) use ($actor, &$queued): void {
                $this->dispatchApply($mailDomain, $mailDomain->domain, $mailDomain->domain->account, $actor);
                $queued++;
            });

        return $queued;
    }

    /**
     * The full address of a mailbox.
     */
    public function address(Mailbox $mailbox): string
    {
        return $mailbox->local_part.'@'.$mailbox->mailDomain->domain->name;
    }

    /**
     * The domain's mail settings, made (and prepared by the agent) when it
     * has none yet or the last try failed.
     */
    private function ensureMailDomain(Domain $domain, User $account, User $actor): MailDomain
    {
        $mailDomain = $domain->mailDomain;

        if ($mailDomain === null) {
            $mailDomain = new MailDomain;
            $mailDomain->forceFill(['domain_id' => $domain->id, 'dkim_selector' => self::SELECTOR, 'status' => MailDomainStatus::Pending])->save();

            $this->audit->record('mail_domain.enabled', $domain, ['domain' => $domain->name], $actor);
            $this->dispatchApply($mailDomain, $domain, $account, $actor);
        } elseif ($mailDomain->status === MailDomainStatus::Failed) {
            $mailDomain->forceFill(['status' => MailDomainStatus::Pending])->save();
            $this->dispatchApply($mailDomain, $domain, $account, $actor);
        }

        return $mailDomain;
    }

    private function dispatchApply(MailDomain $mailDomain, Domain $domain, User $account, ?User $actor): void
    {
        $this->tasks->dispatch('mail.domain_apply', [
            'username' => $account->username,
            'domain' => $domain->name,
            'selector' => $mailDomain->dkim_selector,
        ], $account, $actor);
    }

    /**
     * @throws ValidationException
     */
    private function ensureAccountIsActive(User $account, string $errorKey): void
    {
        if (! $account->hasRole(Role::User) || $account->status !== AccountStatus::Active) {
            throw ValidationException::withMessages([
                $errorKey => __('Email accounts can only be changed while the account is active.'),
            ]);
        }
    }

    /**
     * Refuse a mailbox the account's package has no room for. The
     * account's row stays locked until the mailbox is saved.
     *
     * @throws ValidationException
     */
    private function ensureLimitIsNotReached(User $account, User $actor): void
    {
        $locked = User::query()->with('package')->lockForUpdate()->findOrFail($account->id);
        $limits = AccountLimits::for($locked);

        if (! $limits->isReached('email_accounts')) {
            return;
        }

        $replace = ['limit' => $limits->limit('email_accounts')];

        throw ValidationException::withMessages([
            'local_part' => $actor->is($account)
                ? __('Your package allows :limit email accounts. Remove one or ask your provider for a bigger package.', $replace)
                : __("This account's package allows :limit email accounts.", $replace),
        ]);
    }
}
