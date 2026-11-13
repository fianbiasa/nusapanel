<?php

namespace App\Http\Controllers;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\TicketService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tickets: the ones an account opened, and for resellers and
 * administrators the ones they answer.
 */
class TicketController extends Controller
{
    public const MAX_BODY = 10000;

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
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'body' => ['required', 'string', 'max:'.self::MAX_BODY],
        ]);

        $ticket = $this->tickets->open($request->user(), trim($validated['subject']), TicketPriority::from($validated['priority']), $validated['body']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The ticket is sent.')]);

        return to_route('tickets.show', $ticket);
    }

    public function show(Request $request, Ticket $ticket): Response
    {
        Gate::authorize('view', $ticket);

        $user = $request->user();
        $ticket->load(['user:id,username,name', 'messages' => fn ($query) => $query->orderBy('id'), 'messages.author:id,username,name,role']);

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
            ]),
            'max_body' => self::MAX_BODY,
        ]);
    }

    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        Gate::authorize('reply', $ticket);

        $validated = $request->validate(['body' => ['required', 'string', 'max:'.self::MAX_BODY]]);

        $this->tickets->reply($ticket, $request->user(), $validated['body']);

        return back();
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
