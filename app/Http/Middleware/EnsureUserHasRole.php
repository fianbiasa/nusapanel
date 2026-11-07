<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Allow the request only for the given roles, e.g. `role:admin,reseller`.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $allowed = array_map(fn (string $role): Role => Role::from($role), $roles);

        abort_unless($user instanceof User && $user->hasRole(...$allowed), Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
