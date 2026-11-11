<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\MailDomain;
use App\Models\MailForwarder;
use App\Models\User;
use App\Support\AccountLimits;
use App\Support\MailAddress;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds, changes and removes email forwarders, and sets the catch-all of a
 * mail domain.
 *
 * Postfix reads both straight from the panel's database
 * (v_mail_forwarders, v_mail_catchalls), so a change takes effect at
 * once. A domain's first forwarder sets up its mail, as its first mailbox
 * does. A catch-all only takes mail for addresses the domain does not
 * have; one that sends to an address of its own domain needs that address
 * to exist, or the mail would go round in circles.
 */
class MailForwarderService
{
    public function __construct(
        private MailService $mail,
        private AuditLogger $audit,
    ) {}

    /**
     * Add the forwarder <localPart>@<domain> to the account, within its
     * package's forwarder limit.
     *
     * @param  string  $localPart  Already validated.
     * @param  list<string>  $destinations  Already normalized and validated.
     *
     * @throws ValidationException
     */
    public function create(User $account, int $domainId, string $localPart, array $destinations, User $actor): MailForwarder
    {
        $this->mail->ensureAccountIsActive($account, 'local_part');

        $domain = collect($this->mail->domainsOf($account))->firstWhere('id', $domainId);

        if (! $domain instanceof Domain) {
            throw ValidationException::withMessages(['domain_id' => __('Choose a domain of this account.')]);
        }

        $address = $localPart.'@'.$domain->name;
        $this->ensureDoesNotForwardToItself($address, $destinations);

        try {
            return DB::transaction(function () use ($account, $domain, $localPart, $address, $destinations, $actor): MailForwarder {
                $this->ensureLimitIsNotReached($account, $actor);

                $mailDomain = $this->mail->ensureMailDomain($domain, $account, $actor);

                $forwarder = $mailDomain->forwarders()->make(['local_part' => $localPart]);
                $forwarder->forceFill(['destinations' => implode(',', $destinations)])->save();

                $this->audit->record('mail_forwarder.created', $forwarder, [
                    'address' => $address,
                    'destinations' => $destinations,
                ], $actor);

                return $forwarder;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['local_part' => __('This address already has a forwarder.')]);
        }
    }

    /**
     * Send the forwarder's mail to other addresses.
     *
     * @param  list<string>  $destinations  Already normalized and validated.
     *
     * @throws ValidationException
     */
    public function update(MailForwarder $forwarder, array $destinations, User $actor): void
    {
        $this->mail->ensureAccountIsActive($forwarder->mailDomain->domain->account, 'destinations');

        $address = $this->address($forwarder);
        $this->ensureDoesNotForwardToItself($address, $destinations);

        $forwarder->forceFill(['destinations' => implode(',', $destinations)])->save();

        $this->audit->record('mail_forwarder.updated', $forwarder, [
            'address' => $address,
            'destinations' => $destinations,
        ], $actor);
    }

    /**
     * Remove a forwarder. One the domain's catch-all sends to stays until
     * the catch-all changes, unless a mailbox keeps its address.
     *
     * @throws ValidationException
     */
    public function delete(MailForwarder $forwarder, User $actor): void
    {
        $mailDomain = $forwarder->mailDomain;
        $address = $this->address($forwarder);

        if ($mailDomain->catchall_target === $address
            && ! $mailDomain->mailboxes()->where('local_part', $forwarder->local_part)->exists()) {
            throw ValidationException::withMessages([
                'forwarder' => __('The catch-all of :domain sends mail to this address. Change the catch-all first.', ['domain' => $mailDomain->domain->name]),
            ]);
        }

        $this->audit->record('mail_forwarder.deleted', $forwarder, ['address' => $address], $actor);

        $forwarder->delete();
    }

    /**
     * Send the domain's mail for addresses it does not have to target, or
     * refuse that mail again (null).
     *
     * @param  string|null  $target  Already normalized and validated.
     *
     * @throws ValidationException
     */
    public function setCatchAll(MailDomain $mailDomain, ?string $target, User $actor): void
    {
        $domain = $mailDomain->domain;

        $this->mail->ensureAccountIsActive($domain->account, 'catchall_target');

        if ($target !== null && MailAddress::domain($target) === $domain->name) {
            $localPart = substr($target, 0, -strlen('@'.$domain->name));

            if (! $mailDomain->mailboxes()->where('local_part', $localPart)->exists()
                && ! $mailDomain->forwarders()->where('local_part', $localPart)->exists()) {
                throw ValidationException::withMessages([
                    'catchall_target' => __('Create :address as an email account or forwarder first.', ['address' => $target]),
                ]);
            }
        }

        $mailDomain->forceFill(['catchall_target' => $target])->save();

        $this->audit->record('mail_domain.catchall_updated', $domain, [
            'domain' => $domain->name,
            'target' => $target,
        ], $actor);
    }

    /**
     * The full address of a forwarder.
     */
    public function address(MailForwarder $forwarder): string
    {
        return $forwarder->local_part.'@'.$forwarder->mailDomain->domain->name;
    }

    /**
     * @param  list<string>  $destinations
     *
     * @throws ValidationException
     */
    private function ensureDoesNotForwardToItself(string $address, array $destinations): void
    {
        if (in_array($address, $destinations, true)) {
            throw ValidationException::withMessages([
                'destinations' => __('A forwarder cannot send mail to its own address.'),
            ]);
        }
    }

    /**
     * Refuse a forwarder the account's package has no room for. The
     * account's row stays locked until the forwarder is saved.
     *
     * @throws ValidationException
     */
    private function ensureLimitIsNotReached(User $account, User $actor): void
    {
        $locked = User::query()->with('package')->lockForUpdate()->findOrFail($account->id);
        $limits = AccountLimits::for($locked);

        if (! $limits->isReached('email_forwarders')) {
            return;
        }

        $replace = ['limit' => $limits->limit('email_forwarders')];

        throw ValidationException::withMessages([
            'local_part' => $actor->is($account)
                ? __('Your package allows :limit email forwarders. Remove one or ask your provider for a bigger package.', $replace)
                : __("This account's package allows :limit email forwarders.", $replace),
        ]);
    }
}
