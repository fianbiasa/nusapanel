<?php

namespace App\Http\Controllers;

use App\Enums\MailDomainStatus;
use App\Http\Requests\StoreMailboxRequest;
use App\Http\Requests\UpdateMailboxRequest;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Services\MailService;
use App\Services\WebmailService;
use App\Support\AccountLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EmailController extends Controller
{
    public function __construct(private MailService $mail) {}

    /**
     * List the signed-in account's mailboxes, the domains a mailbox can be
     * on, and how mail apps connect.
     */
    public function index(Request $request): Response
    {
        $account = $request->user();
        $domains = $this->mail->domainsOf($account);

        $mailboxes = Mailbox::query()
            ->with('mailDomain.domain')
            ->whereHas('mailDomain.domain', fn ($query) => $query->where('user_id', $account->id))
            ->get()
            ->sortBy(fn (Mailbox $mailbox): string => $mailbox->mailDomain->domain->name.' '.$mailbox->local_part)
            ->map(fn (Mailbox $mailbox): array => [
                'id' => $mailbox->id,
                'address' => $this->mail->address($mailbox),
                'domain' => $mailbox->mailDomain->domain->name,
                'quota_mb' => $mailbox->quota_mb,
                'ready' => $mailbox->mailDomain->status === MailDomainStatus::Active,
                'created_at' => $mailbox->created_at?->toIso8601String(),
            ])
            ->values();

        return Inertia::render('email/index', [
            'mailboxes' => $mailboxes,
            'domains' => array_map(fn (Domain $domain): array => [
                'id' => $domain->id,
                'name' => $domain->name,
                'mail_status' => $domain->mailDomain?->status->value,
            ], $domains),
            'max_quota_mb' => MailService::MAX_QUOTA_MB,
            'limits' => AccountLimits::for($account)->toArray(),
            'server' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost',
            'webmail' => [
                'available' => WebmailService::isAvailable(),
                'url' => WebmailService::url(),
                'session_hours' => WebmailService::sessionHours(),
            ],
        ]);
    }

    /**
     * Add a mailbox; a domain's first mailbox has its mail set up.
     */
    public function store(StoreMailboxRequest $request): RedirectResponse
    {
        $account = $request->user();

        $mailbox = $this->mail->createMailbox(
            $account,
            $request->integer('domain_id'),
            $request->string('local_part')->toString(),
            $request->string('password')->toString(),
            $request->filled('quota_mb') ? $request->integer('quota_mb') : null,
            $account,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Email account :address is created.', ['address' => $this->mail->address($mailbox)])]);

        return to_route('email.index');
    }

    /**
     * Change a mailbox's password and quota.
     */
    public function update(UpdateMailboxRequest $request, Mailbox $mailbox): RedirectResponse
    {
        $this->mail->updateMailbox(
            $mailbox,
            $request->filled('password') ? $request->string('password')->toString() : null,
            $request->filled('quota_mb') ? $request->integer('quota_mb') : null,
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Email account :address is updated.', ['address' => $this->mail->address($mailbox)])]);

        return back();
    }

    /**
     * Remove a mailbox and its mail.
     */
    public function destroy(Request $request, Mailbox $mailbox): RedirectResponse
    {
        Gate::authorize('delete', $mailbox);

        $address = $this->mail->address($mailbox);

        $this->mail->deleteMailbox($mailbox, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Email account :address is deleted.', ['address' => $address])]);

        return back();
    }
}
