<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "Login as": an administrator signs in as a reseller or hosting account,
 * a reseller as one of its hosting accounts, and returns to its own account
 * later. The session remembers who signed in; while it does, every audit
 * log entry names them as the impersonator, and the account's own login
 * history is left alone. A password confirmation does not carry over, so
 * whoever signed in as the account cannot change its password, email,
 * two-factor authentication or passkeys without knowing its password.
 */
class ImpersonationService
{
    /**
     * The session key that holds the id of the account that signed in as
     * the current one.
     */
    public const SESSION_KEY = 'impersonator_id';

    /**
     * The request attribute set while the session switches accounts, so
     * the switch is not recorded as a login.
     */
    public const SWITCHING = 'rayxo.switching_account';

    public function __construct(private AuditLogger $audit) {}

    /**
     * Sign the session in as account (the caller has checked that the
     * signed-in account may), remembering who did.
     *
     * @throws AuthorizationException While the session already signed in as someone else.
     */
    public function start(Request $request, User $account): void
    {
        if ($this->impersonating($request)) {
            throw new AuthorizationException(__('Return to your own account first.'));
        }

        /** @var User $impersonator */
        $impersonator = $request->user();

        $this->audit->record('account.login_as', $account, ['username' => $account->username], $impersonator);

        $this->switchTo($request, $account);
        $request->session()->put(self::SESSION_KEY, $impersonator->id);
    }

    /**
     * Return the session to the account that signed in as the current one.
     * When that account is gone or may no longer sign in, the session is
     * signed out instead, and null is returned.
     */
    public function stop(Request $request): ?User
    {
        $impersonator = $this->impersonator($request);
        $account = $request->user();

        if ($impersonator !== null && $account instanceof User) {
            $this->audit->record('account.login_as_ended', $account, ['username' => $account->username], $impersonator);
        }

        $request->session()->forget(self::SESSION_KEY);

        if ($impersonator === null || ! $impersonator->status->canLogIn()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return null;
        }

        $this->switchTo($request, $impersonator);

        return $impersonator;
    }

    public function impersonating(Request $request): bool
    {
        return $request->hasSession() && $request->session()->has(self::SESSION_KEY);
    }

    /**
     * The account that signed in as the current one, if any.
     */
    public function impersonator(Request $request): ?User
    {
        if (! $this->impersonating($request)) {
            return null;
        }

        $id = $request->session()->get(self::SESSION_KEY);

        return is_numeric($id) ? User::query()->find((int) $id) : null;
    }

    private function switchTo(Request $request, User $account): void
    {
        $request->attributes->set(self::SWITCHING, true);

        Auth::guard('web')->login($account);

        $request->attributes->remove(self::SWITCHING);
        $request->session()->forget('auth.password_confirmed_at');
    }
}
