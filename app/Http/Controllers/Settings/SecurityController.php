<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\UserAgent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'canManagePasskeys' => Features::canManagePasskeys(),
            'passkeys' => Features::canManagePasskeys()
                ? $request->user()
                    ->passkeys()
                    ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
                    ->latest()
                    ->get()
                    ->map(fn ($passkey) => [
                        'id' => $passkey->id,
                        'name' => $passkey->name,
                        'authenticator' => $passkey->authenticator,
                        'created_at_diff' => $passkey->created_at->diffForHumans(),
                        'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
                    ])
                    ->values()
                    ->all()
                : [],
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'loginHistory' => $this->loginHistory($request->user()),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $request->user()->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('settings/security', $props);
    }

    /**
     * The account's latest logins and failed attempts, newest first, as the
     * audit log recorded them.
     *
     * @return list<array{id: int, succeeded: bool, ip_address: string|null, browser: string|null, os: string|null, created_at: string}>
     */
    private function loginHistory(User $user): array
    {
        $entries = AuditLog::query()
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->whereIn('action', ['auth.login', 'auth.failed'])
            ->latest('created_at')
            ->latest('id')
            ->limit(20)
            ->get(['id', 'action', 'ip_address', 'user_agent', 'created_at']);

        $history = [];

        foreach ($entries as $entry) {
            $device = UserAgent::describe($entry->user_agent);

            $history[] = [
                'id' => (int) $entry->id,
                'succeeded' => $entry->action === 'auth.login',
                'ip_address' => $entry->ip_address,
                'browser' => $device['browser'],
                'os' => $device['os'],
                'created_at' => $entry->created_at->toIso8601String(),
            ];
        }

        return $history;
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $request->user()->update([
            'password' => $request->password,
        ]);

        // Whoever was signed in with the old password, elsewhere, is out.
        $request->user()->endSessions(except: $request->session()->getId());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return back();
    }
}
