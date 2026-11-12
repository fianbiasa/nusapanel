<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Login as" from the account list, and the way back.
 */
class ImpersonationController extends Controller
{
    public function __construct(private ImpersonationService $impersonation) {}

    /**
     * Sign in as the account. The page reloads fully, as the menu and
     * everything shown belong to another account now.
     */
    public function store(Request $request, User $account): Response
    {
        Gate::authorize('loginAs', $account);

        $this->impersonation->start($request, $account);

        return Inertia::location(route('dashboard'));
    }

    /**
     * Return to the account that signed in as this one.
     */
    public function destroy(Request $request): Response
    {
        abort_unless($this->impersonation->impersonating($request), 404);

        $impersonator = $this->impersonation->stop($request);

        if ($impersonator === null) {
            return Inertia::location(route('login'));
        }

        return Inertia::location(route($impersonator->isAdmin() ? 'admin.accounts.index' : 'reseller.accounts.index'));
    }
}
