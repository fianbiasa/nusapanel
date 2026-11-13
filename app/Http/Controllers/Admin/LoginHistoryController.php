<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\UserAgent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The logins and failed login attempts of accounts, newest first, as the
 * audit log recorded them: of every account for administrators (with the
 * attempts on names that are no account), of its own hosting accounts for
 * a reseller.
 */
class LoginHistoryController extends Controller
{
    /**
     * The actions of the audit log that are logins.
     *
     * @var list<string>
     */
    private const ACTIONS = ['auth.login', 'auth.failed'];

    /**
     * List the logins, filtered by result and by a username or IP address.
     */
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'result' => ['nullable', Rule::in(['succeeded', 'failed'])],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $result = (string) ($validated['result'] ?? '');
        $user = $request->user();
        $accounts = User::query()->select('id');

        $entries = AuditLog::query()
            ->whereIn('action', self::ACTIONS)
            ->when($user->isReseller(), fn (Builder $query) => $query
                ->where('subject_type', $user->getMorphClass())
                ->whereIn('subject_id', (clone $accounts)->where('owner_id', $user->id)))
            ->when($result !== '', fn (Builder $query) => $query->where('action', $result === 'succeeded' ? 'auth.login' : 'auth.failed'))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query
                    ->where('subject_type', $user->getMorphClass())
                    ->whereIn('subject_id', (clone $accounts)->where('username', 'like', "%{$search}%")))
                ->orWhere('changes->username', 'like', "%{$search}%")
                ->orWhere('ip_address', 'like', "{$search}%")))
            ->latest('created_at')
            ->latest('id')
            ->paginate(50, ['id', 'action', 'subject_type', 'subject_id', 'ip_address', 'user_agent', 'changes', 'created_at'])
            ->withQueryString();

        $usernames = User::query()
            ->whereIn('id', $entries->getCollection()
                ->where('subject_type', $user->getMorphClass())
                ->pluck('subject_id')
                ->unique()
                ->values())
            ->pluck('username', 'id');

        $scope = $user->isReseller() ? 'reseller' : 'admin';

        return Inertia::render('admin/login-history/index', [
            'scope' => $scope,
            'logins' => $entries->through(function (AuditLog $entry) use ($usernames, $user): array {
                $device = UserAgent::describe($entry->user_agent);
                $account = $entry->subject_type === $user->getMorphClass() ? $usernames->get((int) $entry->subject_id) : null;
                $attempted = $entry->changes['username'] ?? null;

                return [
                    'id' => $entry->id,
                    'succeeded' => $entry->action === 'auth.login',
                    'username' => $account,
                    'attempted' => $account === null && is_string($attempted) && $attempted !== '' ? $attempted : null,
                    'ip_address' => $entry->ip_address,
                    'browser' => $device['browser'],
                    'os' => $device['os'],
                    'created_at' => $entry->created_at->toIso8601String(),
                ];
            }),
            'filters' => [
                'search' => $search,
                'result' => $result,
            ],
        ]);
    }
}
