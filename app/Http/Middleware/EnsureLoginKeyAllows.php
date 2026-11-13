<?php

namespace App\Http\Middleware;

use App\Models\LoginKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets an API request through only when its login key has the permission
 * the route needs ("login-key:domains:write").
 */
class EnsureLoginKeyAllows
{
    public function handle(Request $request, Closure $next, string ...$permission): Response
    {
        // The permission has a colon, which the middleware syntax splits.
        $needed = implode(':', $permission);
        $key = $request->attributes->get('login_key');

        abort_unless($key instanceof LoginKey && $key->allows($needed), Response::HTTP_FORBIDDEN, __('This login key does not have the :permission permission.', ['permission' => $needed]));

        return $next($request);
    }
}
