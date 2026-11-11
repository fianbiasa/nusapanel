<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Enums\MailboxStatus;
use App\Enums\MailDomainMode;
use App\Enums\MailDomainStatus;
use App\Enums\MailProvider;
use App\Enums\Role;
use App\Enums\SslStatus;
use App\Listeners\ApplyMailTaskResult;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\Task;
use App\Models\User;
use App\Support\AccountLimits;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        private WebmailService $webmail,
        private MailSendLimitService $sendLimits,
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
                $mailbox->forceFill(['password_hash' => Mailbox::hashPassword($password), 'status' => MailboxStatus::Active])->save();

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
     * limit). Dovecot checks both at the next sign-in and delivery; a new
     * password also ends the webmail sessions opened from the panel.
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

        if ($password !== null) {
            $this->webmail->endSessions($mailbox);
        }

        $this->audit->record('mailbox.updated', $mailbox, ['address' => $this->address($mailbox), ...$changes], $actor);
    }

    /**
     * Give a mailbox its own daily send limit for what it sends signed in
     * (recipients per 24 hours), or the account's when null; the mail
     * server gets it at once.
     *
     * @throws ValidationException
     */
    public function setMailboxSendLimit(Mailbox $mailbox, ?int $limit, User $actor): void
    {
        $this->ensureAccountIsActive($mailbox->mailDomain->domain->account, 'daily_send_limit');

        if ($limit === $mailbox->daily_send_limit) {
            return;
        }

        $mailbox->forceFill(['daily_send_limit' => $limit])->save();

        $this->audit->record('mailbox.send_limit_changed', $mailbox, ['address' => $this->address($mailbox), 'daily_send_limit' => $limit], $actor);

        $this->sendLimits->sync($actor);
    }

    /**
     * Suspend a mailbox, or let it sign in again. A suspended mailbox may
     * not sign in (IMAP, POP3, SMTP, webmail), so it sends nothing, but its
     * mail still arrives; its webmail sessions end.
     *
     * @throws ValidationException
     */
    public function setMailboxSuspended(Mailbox $mailbox, bool $suspended, User $actor): void
    {
        $this->ensureAccountIsActive($mailbox->mailDomain->domain->account, 'suspended');

        $status = $suspended ? MailboxStatus::Suspended : MailboxStatus::Active;

        if ($mailbox->status === $status) {
            return;
        }

        $mailbox->forceFill(['status' => $status])->save();

        if ($suspended) {
            $this->webmail->endSessions($mailbox);
        }

        $this->audit->record($suspended ? 'mailbox.suspended' : 'mailbox.unsuspended', $mailbox, ['address' => $this->address($mailbox)], $actor);
    }

    /**
     * Remove a mailbox and delete its mail, and what webmail kept of it.
     * A mailbox the domain's catch-all sends to stays until the catch-all
     * changes, unless a forwarder keeps its address.
     *
     * @throws ValidationException
     */
    public function deleteMailbox(Mailbox $mailbox, User $actor): void
    {
        $mailDomain = $mailbox->mailDomain;
        $domain = $mailDomain->domain;
        $account = $domain->account;
        $address = $this->address($mailbox);

        if ($mailDomain->catchall_target === $address
            && ! $mailDomain->forwarders()->where('local_part', $mailbox->local_part)->exists()) {
            throw ValidationException::withMessages([
                'mailbox' => __('The catch-all of :domain sends mail to this address. Change the catch-all first.', ['domain' => $domain->name]),
            ]);
        }

        DB::transaction(function () use ($mailbox, $domain, $account, $address, $actor): void {
            $this->audit->record('mailbox.deleted', $mailbox, ['address' => $address], $actor);

            $mailbox->delete();

            $this->tasks->dispatch('mail.mailbox_remove', [
                'username' => $account->username,
                'domain' => $domain->name,
                'local_part' => $mailbox->local_part,
            ], $account, $actor);
        });

        $this->webmail->forget([$address]);
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

        if ($mailDomain->ssl_status === null && $mailDomain->mode !== MailDomainMode::External) {
            $this->requestMailSsl($mailDomain, $actor);
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
     * Get mail.<domain> a certificate, so mail apps can use it as their
     * server name (SNI): one of the domain's that covers it, such as its
     * wildcard, or its own from Let's Encrypt, which needs mail.<domain>
     * to point at this server. Asked once a domain takes mail, and again
     * from the Email page after a failure.
     *
     * @throws ValidationException
     */
    public function requestMailSsl(MailDomain $mailDomain, ?User $actor): void
    {
        $domain = $mailDomain->domain;

        if ($mailDomain->status !== MailDomainStatus::Active) {
            throw ValidationException::withMessages(['ssl' => __('The email of :domain is still being set up.', ['domain' => $domain->name])]);
        }

        if ($mailDomain->ssl_status === SslStatus::Pending) {
            return;
        }

        $mailDomain->forceFill(['ssl_status' => SslStatus::Pending, 'ssl_error' => null])->save();

        $this->tasks->dispatch('mail.ssl_issue', [
            'username' => $domain->account->username,
            'domain' => $domain->name,
        ], $domain->account, $actor);
    }

    /**
     * mail.<domain> has its certificate: Dovecot and Postfix serve it.
     */
    public function completeMailSsl(MailDomain $mailDomain, ?User $actor): void
    {
        $mailDomain->forceFill(['ssl_status' => SslStatus::Active, 'ssl_error' => null])->save();

        $this->syncMailSni($actor);
    }

    /**
     * mail.<domain> got no certificate; error says why.
     */
    public function failMailSsl(MailDomain $mailDomain, ?string $error): void
    {
        $mailDomain->forceFill([
            'ssl_status' => SslStatus::Failed,
            'ssl_error' => $error === null ? null : Str::limit($error, 480),
        ])->save();
    }

    /**
     * Have Dovecot and Postfix serve mail.<domain> of every mail domain
     * with the certificate it has now (mail.sni_apply, for the whole
     * server). Domains without one keep the hostname's.
     */
    public function syncMailSni(?User $actor = null): Task
    {
        $domains = MailDomain::query()
            ->with('domain')
            ->where('status', MailDomainStatus::Active)
            ->get()
            ->map(fn (MailDomain $mailDomain): string => $mailDomain->domain->name)
            ->sort()
            ->values()
            ->all();

        return $this->tasks->dispatch('mail.sni_apply', ['domains' => $domains], null, $actor);
    }

    /**
     * Have this server take a domain's mail (local), or another service
     * (external). Postfix then sends the domain's mail, also from this
     * server's websites and accounts, to the service's MX, while the
     * domain's mailboxes can still sign in so old mail can be moved. For
     * Google Workspace and Zoho the domain's zone gets the service's MX and
     * SPF records. A domain without mail settings gets them first, so this
     * server still DKIM-signs its websites' mail.
     *
     * @param  MailProvider|null  $provider  The service, for external; ignored for local.
     * @return bool Whether the domain's DNS zone was changed.
     *
     * @throws ValidationException
     */
    public function setDomainMode(Domain $domain, MailDomainMode $mode, ?MailProvider $provider, User $actor): bool
    {
        $account = $domain->account;

        $this->ensureAccountIsActive($account, 'mode');

        if (! collect($this->domainsOf($account))->contains('id', $domain->id)) {
            throw ValidationException::withMessages(['mode' => __('Choose a domain of this account.')]);
        }

        $provider = $mode === MailDomainMode::External ? $provider : null;

        if ($mode === MailDomainMode::External && $provider === null) {
            throw ValidationException::withMessages(['provider' => __('Choose where the mail goes.')]);
        }

        return DB::transaction(function () use ($domain, $account, $mode, $provider, $actor): bool {
            $mailDomain = $domain->mailDomain;

            // A domain without mail settings already leaves its mail to its MX.
            if ($mailDomain === null && $mode === MailDomainMode::Local) {
                return false;
            }

            $mailDomain ??= $this->ensureMailDomain($domain, $account, $actor);
            $from = $mailDomain->mode === MailDomainMode::External ? $mailDomain->provider : null;

            $mailDomain->forceFill(['mode' => $mode, 'provider' => $provider])->save();

            $this->audit->record('mail_domain.mode_updated', $domain, [
                'domain' => $domain->name,
                'mode' => $mode->value,
                'provider' => $provider?->value,
            ], $actor);

            return $this->dns->useMailProvider($domain, $from, $provider, $actor);
        });
    }

    /**
     * Stop taking mail for a domain that is being deleted: its DKIM key and
     * mail go from the server, its mailboxes from the panel, and what
     * webmail kept of them.
     */
    public function removeDomain(Domain $domain, ?User $actor): void
    {
        $mailDomain = $domain->mailDomain;

        if ($mailDomain === null) {
            return;
        }

        $addresses = $this->addressesOf($mailDomain);

        $this->tasks->dispatch('mail.domain_remove', [
            'username' => $domain->account->username,
            'domain' => $domain->name,
        ], $domain->account, $actor);

        $mailDomain->delete();

        $this->webmail->forget($addresses);
    }

    /**
     * Remove the DKIM keys of every mail domain of an account that is being
     * deleted, and what webmail kept of its mailboxes; its mail goes with
     * its home.
     */
    public function removeAccount(User $account, ?User $actor): void
    {
        $addresses = [];

        MailDomain::query()
            ->with('domain')
            ->whereHas('domain', fn ($query) => $query->where('user_id', $account->id))
            ->orderBy('id')
            ->each(function (MailDomain $mailDomain) use ($account, $actor, &$addresses): void {
                $addresses = [...$addresses, ...$this->addressesOf($mailDomain)];

                $this->tasks->dispatch('mail.domain_remove', [
                    'username' => $account->username,
                    'domain' => $mailDomain->domain->name,
                ], $account, $actor);
            });

        $this->webmail->forget($addresses);
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
     * The addresses of the mail domain's mailboxes.
     *
     * @return list<string>
     */
    private function addressesOf(MailDomain $mailDomain): array
    {
        return array_values($mailDomain->mailboxes()
            ->orderBy('id')
            ->pluck('local_part')
            ->map(fn (string $localPart): string => $localPart.'@'.$mailDomain->domain->name)
            ->all());
    }

    /**
     * The domain's mail settings, made (and prepared by the agent) when it
     * has none yet or the last try failed: a domain's first mailbox or
     * forwarder sets up its mail.
     */
    public function ensureMailDomain(Domain $domain, User $account, User $actor): MailDomain
    {
        $mailDomain = $domain->mailDomain;

        if ($mailDomain === null) {
            $mailDomain = new MailDomain;
            $mailDomain->forceFill(['domain_id' => $domain->id, 'dkim_selector' => self::SELECTOR, 'status' => MailDomainStatus::Pending, 'mode' => MailDomainMode::Local])->save();

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
     * Refuse changes to the email of an account that is not an active
     * hosting account, as an error on errorKey.
     *
     * @throws ValidationException
     */
    public function ensureAccountIsActive(User $account, string $errorKey): void
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
