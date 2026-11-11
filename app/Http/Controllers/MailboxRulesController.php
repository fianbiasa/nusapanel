<?php

namespace App\Http\Controllers;

use App\Enums\MailDomainStatus;
use App\Http\Requests\StoreMailFilterRequest;
use App\Http\Requests\UpdateAutoreplyRequest;
use App\Models\Mailbox;
use App\Models\MailFilter;
use App\Services\MailRulesService;
use App\Services\MailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MailboxRulesController extends Controller
{
    public function __construct(
        private MailRulesService $rules,
        private MailService $mail,
    ) {}

    /**
     * A mailbox's automatic reply and filters.
     */
    public function show(Request $request, Mailbox $mailbox): Response
    {
        Gate::authorize('update', $mailbox);

        $autoreply = $mailbox->autoreply;

        return Inertia::render('email/rules', [
            'mailbox' => [
                'id' => $mailbox->id,
                'address' => $this->mail->address($mailbox),
                'ready' => $mailbox->mailDomain->status === MailDomainStatus::Active,
            ],
            'autoreply' => $autoreply === null ? null : [
                'enabled' => $autoreply->enabled,
                'subject' => $autoreply->subject,
                'body' => $autoreply->body,
                'starts_on' => $autoreply->starts_on?->format('Y-m-d'),
                'ends_on' => $autoreply->ends_on?->format('Y-m-d'),
                'interval_days' => $autoreply->interval_days,
            ],
            'filters' => $mailbox->filters->map(fn (MailFilter $filter): array => [
                'id' => $filter->id,
                'field' => $filter->field,
                'operator' => $filter->operator,
                'value' => $filter->value,
                'action' => $filter->action,
                'target' => $filter->target,
            ])->values(),
            'max_filters' => MailRulesService::MAX_FILTERS,
        ]);
    }

    /**
     * Save the mailbox's automatic reply.
     */
    public function updateAutoreply(UpdateAutoreplyRequest $request, Mailbox $mailbox): RedirectResponse
    {
        $data = $request->autoreply();

        $this->rules->saveAutoreply($mailbox, $data, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => $data['enabled']
            ? __('The automatic reply of :address is on.', ['address' => $this->mail->address($mailbox)])
            : __('The automatic reply of :address is off.', ['address' => $this->mail->address($mailbox)])]);

        return back();
    }

    /**
     * Add a filter to the mailbox.
     */
    public function storeFilter(StoreMailFilterRequest $request, Mailbox $mailbox): RedirectResponse
    {
        $this->rules->createFilter($mailbox, $request->filter(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The filter is added.')]);

        return back();
    }

    /**
     * Remove a filter.
     */
    public function destroyFilter(Request $request, MailFilter $mailFilter): RedirectResponse
    {
        Gate::authorize('delete', $mailFilter);

        $this->rules->deleteFilter($mailFilter, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The filter is deleted.')]);

        return back();
    }
}
