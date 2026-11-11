<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Models\MailAutoreply;
use App\Models\Mailbox;
use App\Models\MailFilter;
use App\Models\User;
use App\Support\SieveScript;
use Illuminate\Validation\ValidationException;

/**
 * The automatic reply and filters of mailboxes. Every change writes the
 * mailbox's whole Sieve script again ({@see SieveScript}), which the agent
 * puts in the mailbox's folder as the account (mail.sieve_apply); Dovecot
 * runs it on the next delivery.
 */
class MailRulesService
{
    /**
     * The most filters one mailbox may have.
     */
    public const MAX_FILTERS = 50;

    public function __construct(
        private MailService $mail,
        private TaskDispatcher $tasks,
        private AuditLogger $audit,
    ) {}

    /**
     * Save the mailbox's automatic reply.
     *
     * @param  array{enabled: bool, subject: string, body: string, starts_on: string|null, ends_on: string|null, interval_days: int}  $data  Already validated.
     *
     * @throws ValidationException
     */
    public function saveAutoreply(Mailbox $mailbox, array $data, User $actor): MailAutoreply
    {
        $this->mail->ensureAccountIsActive($mailbox->mailDomain->domain->account, 'subject');

        $autoreply = $mailbox->autoreply()->firstOrNew();
        $autoreply->forceFill($data)->save();

        $this->audit->record('mailbox.autoreply_updated', $mailbox, [
            'address' => $this->mail->address($mailbox),
            'enabled' => $data['enabled'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
        ], $actor);

        $this->apply($mailbox, $actor);

        return $autoreply;
    }

    /**
     * Add a filter after the mailbox's others.
     *
     * @param  array{field: string, operator: string, value: string, action: string, target: string|null}  $data  Already validated.
     *
     * @throws ValidationException
     */
    public function createFilter(Mailbox $mailbox, array $data, User $actor): MailFilter
    {
        $this->mail->ensureAccountIsActive($mailbox->mailDomain->domain->account, 'value');

        if ($mailbox->filters()->count() >= self::MAX_FILTERS) {
            throw ValidationException::withMessages([
                'value' => __('An email account can have at most :max filters.', ['max' => self::MAX_FILTERS]),
            ]);
        }

        if ($data['action'] === 'forward' && $data['target'] === $this->mail->address($mailbox)) {
            throw ValidationException::withMessages([
                'target' => __('A filter cannot forward mail to its own email account.'),
            ]);
        }

        $filter = new MailFilter;
        $filter->forceFill(['mailbox_id' => $mailbox->id, ...$data])->save();

        $this->audit->record('mailbox.filter_created', $mailbox, [
            'address' => $this->mail->address($mailbox),
            ...$data,
        ], $actor);

        $this->apply($mailbox, $actor);

        return $filter;
    }

    /**
     * Remove a filter.
     *
     * @throws ValidationException
     */
    public function deleteFilter(MailFilter $filter, User $actor): void
    {
        $mailbox = $filter->mailbox;

        $this->mail->ensureAccountIsActive($mailbox->mailDomain->domain->account, 'filter');

        $this->audit->record('mailbox.filter_deleted', $mailbox, [
            'address' => $this->mail->address($mailbox),
            'field' => $filter->field,
            'value' => $filter->value,
        ], $actor);

        $filter->delete();

        $this->apply($mailbox, $actor);
    }

    /**
     * Have the agent write the mailbox's Sieve script as it now is; with no
     * automatic reply or filters, the script is removed.
     */
    public function apply(Mailbox $mailbox, ?User $actor): void
    {
        $domain = $mailbox->mailDomain->domain;

        $script = SieveScript::build(
            $this->mail->address($mailbox),
            $mailbox->autoreply()->first(),
            $mailbox->filters()->get(),
        );

        $this->tasks->dispatch('mail.sieve_apply', [
            'username' => $domain->account->username,
            'domain' => $domain->name,
            'local_part' => $mailbox->local_part,
            'script' => $script,
        ], $domain->account, $actor);
    }

    /**
     * Write the Sieve script of every mailbox with an automatic reply or
     * filters again, for active and suspended accounts. Used by
     * rayxo:rebuild.
     *
     * @return int The number of mailboxes queued.
     */
    public function reapplyAll(?User $actor = null): int
    {
        $queued = 0;

        Mailbox::query()
            ->with('mailDomain.domain.account')
            ->where(fn ($query) => $query->whereHas('autoreply')->orWhereHas('filters'))
            ->whereHas('mailDomain.domain.account', fn ($query) => $query
                ->where('role', Role::User)
                ->whereIn('status', [AccountStatus::Active, AccountStatus::Suspended]))
            ->orderBy('id')
            ->each(function (Mailbox $mailbox) use ($actor, &$queued): void {
                $this->apply($mailbox, $actor);
                $queued++;
            });

        return $queued;
    }
}
