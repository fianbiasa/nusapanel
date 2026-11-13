<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Announcement;
use App\Services\AnnouncementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Messages to many accounts: the ones received, and for administrators and
 * resellers the ones they sent.
 */
class AnnouncementController extends Controller
{
    public const MAX_BODY = 10000;

    public function __construct(private AnnouncementService $announcements) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $sends = $user->isAdmin() || $user->isReseller();

        return Inertia::render('announcements/index', [
            'received' => $this->announcements->receivedBy($user)
                ->limit(50)
                ->get()
                ->map(fn (Announcement $announcement): array => $this->row($announcement)),
            'sent' => $sends
                ? Announcement::query()->where('sender_id', $user->id)->latest('id')->limit(50)->get()->map(fn (Announcement $announcement): array => [
                    ...$this->row($announcement),
                    'audience' => $announcement->audience,
                    'recipient_count' => $announcement->recipient_count,
                    'emailed' => $announcement->emailed,
                ])
                : [],
            'can_send' => $sends,
            'audiences' => $user->isAdmin() ? [
                'reseller' => $this->announcements->recipients($user, [Role::Reseller->value])->count(),
                'user' => $this->announcements->recipients($user, [Role::User->value])->count(),
            ] : ($user->isReseller() ? ['user' => $this->announcements->recipients($user, [Role::User->value])->count()] : []),
            'max_body' => self::MAX_BODY,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:'.self::MAX_BODY],
            'audience' => [$user->isAdmin() ? 'required' : 'nullable', 'array', 'min:1'],
            'audience.*' => ['string', 'distinct', Rule::in([Role::Reseller->value, Role::User->value])],
            'email' => ['required', 'boolean'],
        ]);

        $announcement = $this->announcements->send(
            $user,
            array_values($validated['audience'] ?? []),
            trim($validated['subject']),
            $validated['body'],
            (bool) $validated['email'],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The message is sent to :count accounts.', ['count' => $announcement->recipient_count])]);

        return back();
    }

    /**
     * @return array{id: int, subject: string, body: string, from: string, created_at: string|null}
     */
    private function row(Announcement $announcement): array
    {
        return [
            'id' => $announcement->id,
            'subject' => $announcement->subject,
            'body' => $announcement->body,
            'from' => $announcement->sender->isAdmin() ? __('Server administrator') : $announcement->sender->name,
            'created_at' => $announcement->created_at?->toIso8601String(),
        ];
    }
}
