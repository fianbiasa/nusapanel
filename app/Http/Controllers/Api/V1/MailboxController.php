<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMailboxRequest;
use App\Http\Resources\MailboxResource;
use App\Models\Mailbox;
use App\Services\MailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The account's email accounts.
 */
class MailboxController extends Controller
{
    public function __construct(private MailService $mail) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $account = $request->user();

        $mailboxes = Mailbox::query()
            ->with('mailDomain.domain')
            ->whereHas('mailDomain.domain', fn ($query) => $query->where('user_id', $account->id))
            ->orderBy('id')
            ->get();

        return MailboxResource::collection($mailboxes);
    }

    /**
     * Create an email account on one of the account's domains (domain_id).
     */
    public function store(StoreMailboxRequest $request): JsonResponse
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

        return (new MailboxResource($mailbox->load('mailDomain.domain')))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Mailbox $mailbox): Response
    {
        Gate::authorize('delete', $mailbox);

        $this->mail->deleteMailbox($mailbox, $request->user());

        return response()->noContent();
    }
}
