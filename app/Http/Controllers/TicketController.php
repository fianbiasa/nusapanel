<?php

namespace App\Http\Controllers;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Services\TicketService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tickets: the ones an account opened, and for resellers and
 * administrators the ones they answer.
 */
class TicketController extends Controller
{
    public const MAX_BODY = 10000;

    /**
     * How many files a message may carry, and how large each may be (KB).
     */
    public const MAX_ATTACHMENTS = 5;

    public const MAX_ATTACHMENT_KB = 5120;

    /**
     * The kinds of files a message may carry, by their content.
     */
    public const ATTACHMENT_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'csv', 'zip'];

    public function __construct(private TicketService $tickets) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $answers = $user->isAdmin() || $user->isReseller();

        $validated = $request->validate([
            'box' => ['nullable', Rule::in(['mine', 'inbox'])],
            'status' => ['nullable', Rule::enum(TicketStatus::class)],
        ]);

        $box = $validated['box'] ?? ($user->isAdmin() ? 'inbox' : 'mine');
        $box = $answers ? $box : 'mine';

        $tickets = $this->box($request, $box)
            ->with('user:id,username,name')
            ->when(isset($validated['status']), fn (Builder $query) => $query->where('status', $validated['status']))
            ->orderByRaw("case status when 'closed' then 1 else 0 end")
            ->latest('last_message_at')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Ticket $ticket): array => $this->ticketRow($ticket));

        return Inertia::render('tickets/index', [
            'box' => $box,
            'status' => $validated['status'] ?? null,
            'tickets' => $tickets,
            'answers' => $answers,
            'can_open' => ! $user->isAdmin(),
            'recipient' => $user->isAdmin() ? null : ($this->tickets->resellerFor($user) !== null ? 'reseller' : 'admin'),
            'waiting' => [
                'mine' => $this->box($request, 'mine')->where('status', TicketStatus::Answered)->count(),
                'inbox' => $answers ? $this->box($request, 'inbox')->where('status', TicketStatus::Open)->count() : 0,
            ],
            'priorities' => array_map(fn (TicketPriority $priority): string => $priority->value, TicketPriority::cases()),
            'max_body' => self::MAX_BODY,
            'attachment_limits' => $this->attachmentLimits(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'body' => ['required', 'string', 'max:'.self::MAX_BODY],
            ...$this->attachmentRules(),
        ]);

        $ticket = $this->tickets->open($request->user(), trim($validated['subject']), TicketPriority::from($validated['priority']), $validated['body'], $this->attachments($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The ticket is sent.')]);

        return to_route('tickets.show', $ticket);
    }

    public function show(Request $request, Ticket $ticket): Response
    {
        Gate::authorize('view', $ticket);

        $user = $request->user();
        $ticket->load(['user:id,username,name', 'messages' => fn ($query) => $query->orderBy('id'), 'messages.author:id,username,name,role', 'messages.attachments']);

        return Inertia::render('tickets/show', [
            'ticket' => [
                ...$this->ticketRow($ticket),
                'answering' => $ticket->isAnsweredBy($user),
            ],
            'messages' => $ticket->messages->map(fn (TicketMessage $message): array => [
                'id' => $message->id,
                'body' => $message->body,
                'author' => $message->author === null ? null : [
                    'username' => $message->author->username,
                    'name' => $message->author->name,
                    'role' => $message->author->role->value,
                ],
                'mine' => $message->author_id === $user->id,
                'created_at' => $message->created_at?->toIso8601String(),
                'attachments' => $message->attachments->map(fn (TicketAttachment $attachment): array => [
                    'id' => $attachment->id,
                    'name' => $attachment->name,
                    'size' => $attachment->size,
                    'image' => $attachment->isImage(),
                    'url' => route('tickets.attachment', [$ticket, $attachment]),
                ]),
            ]),
            'max_body' => self::MAX_BODY,
            'attachment_limits' => $this->attachmentLimits(),
        ]);
    }

    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        Gate::authorize('reply', $ticket);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:'.self::MAX_BODY],
            ...$this->attachmentRules(),
        ]);

        $this->tickets->reply($ticket, $request->user(), $validated['body'], $this->attachments($request));

        return back();
    }

    /**
     * Send a file of the ticket to one of its two sides. Pictures are shown
     * in the browser; anything else is downloaded. Neither is ever read as
     * a page of the panel.
     */
    public function attachment(Request $request, Ticket $ticket, TicketAttachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $ticket);

        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ];

        return $attachment->isImage()
            ? Storage::disk('local')->response($attachment->path, $attachment->name, ['Content-Type' => $attachment->mime, ...$headers])
            : Storage::disk('local')->download($attachment->path, $attachment->name, ['Content-Type' => 'application/octet-stream', ...$headers]);
    }

    public function close(Request $request, Ticket $ticket): RedirectResponse
    {
        Gate::authorize('reply', $ticket);

        $this->tickets->close($ticket, $request->user());

        return back();
    }

    public function reopen(Request $request, Ticket $ticket): RedirectResponse
    {
        Gate::authorize('reply', $ticket);

        $this->tickets->reopen($ticket, $request->user());

        return back();
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function attachmentRules(): array
    {
        return [
            'attachments' => ['nullable', 'array', 'max:'.self::MAX_ATTACHMENTS],
            'attachments.*' => ['file', 'max:'.self::MAX_ATTACHMENT_KB, 'mimes:'.implode(',', self::ATTACHMENT_TYPES)],
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    private function attachments(Request $request): array
    {
        return array_values(array_filter((array) $request->file('attachments', []), fn (mixed $file): bool => $file instanceof UploadedFile));
    }

    /**
     * @return array{count: int, size_kb: int, types: list<string>}
     */
    private function attachmentLimits(): array
    {
        return ['count' => self::MAX_ATTACHMENTS, 'size_kb' => self::MAX_ATTACHMENT_KB, 'types' => self::ATTACHMENT_TYPES];
    }

    /**
     * The tickets of a box: those the user opened, or those it answers.
     *
     * @return Builder<Ticket>
     */
    private function box(Request $request, string $box): Builder
    {
        $user = $request->user();

        if ($box === 'mine') {
            return Ticket::query()->where('user_id', $user->id);
        }

        return $user->isAdmin()
            ? Ticket::query()->whereNull('reseller_id')
            : Ticket::query()->where('reseller_id', $user->id);
    }

    /**
     * @return array{id: int, subject: string, status: string, priority: string, from: array{username: string, name: string}, last_message_at: string|null, created_at: string|null}
     */
    private function ticketRow(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'status' => $ticket->status->value,
            'priority' => $ticket->priority->value,
            'from' => ['username' => $ticket->user->username, 'name' => $ticket->user->name],
            'last_message_at' => $ticket->last_message_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
        ];
    }
}
