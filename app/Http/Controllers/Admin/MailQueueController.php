<?php

namespace App\Http\Controllers\Admin;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeleteQueuedMailRequest;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The server's mail queue: mail waiting to be delivered (deferred, with
 * the reason), being sent, or held back in the maildrop by the daily send
 * limit. The admin tries messages again now or deletes them, such as spam
 * from a broken website.
 */
class MailQueueController extends Controller
{
    public function __construct(
        private AgentClient $agent,
        private AuditLogger $audit,
    ) {}

    /**
     * The queue, newest first, at most 500 messages.
     */
    public function index(): Response
    {
        $queue = null;
        $error = null;

        try {
            $queue = $this->agent->call('mail.queue_list', [], 30);
        } catch (AgentException $exception) {
            report($exception);
            $error = __('The agent did not answer. Check that rayxod is running: systemctl status rayxod');
        }

        return Inertia::render('admin/mail-queue/index', [
            'queue' => $queue,
            'error' => $error,
        ]);
    }

    /**
     * Have Postfix try one message again now, or every waiting message when
     * no id is given.
     *
     * @throws ValidationException
     */
    public function retry(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id' => ['nullable', 'string', 'regex:'.DeleteQueuedMailRequest::QUEUE_ID],
        ]);
        $id = $validated['id'] ?? null;

        try {
            $this->agent->call('mail.queue_retry', ['id' => $id ?? ''], 60);
        } catch (AgentException $exception) {
            report($exception);

            throw ValidationException::withMessages(['queue' => __('The mail could not be tried again: :detail', ['detail' => $exception->getMessage()])]);
        }

        $this->audit->record('mail.queue_retried', null, ['id' => $id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $id === null
            ? __('Every waiting message is being tried again.')
            : __('Message :id is being tried again.', ['id' => $id])]);

        return back();
    }

    /**
     * Delete messages from the queue for good.
     *
     * @throws ValidationException
     */
    public function destroy(DeleteQueuedMailRequest $request): RedirectResponse
    {
        /** @var list<string> $ids */
        $ids = $request->validated('ids');

        try {
            $outcome = $this->agent->call('mail.queue_delete', ['ids' => $ids], 60);
        } catch (AgentException $exception) {
            report($exception);

            throw ValidationException::withMessages(['queue' => __('The mail could not be deleted: :detail', ['detail' => $exception->getMessage()])]);
        }

        $deleted = array_values(array_filter((array) ($outcome['done'] ?? []), 'is_string'));

        $this->audit->record('mail.queue_deleted', null, ['ids' => $deleted]);

        Inertia::flash('toast', ['type' => 'success', 'message' => count($deleted) === count($ids)
            ? trans_choice(':count message deleted from the queue.|:count messages deleted from the queue.', count($deleted))
            : __(':deleted of :total messages deleted; the others were no longer in the queue.', ['deleted' => count($deleted), 'total' => count($ids)])]);

        return back();
    }
}
