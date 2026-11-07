<?php

namespace App\Http\Middleware;

use App\Support\EmailVerification;
use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified as BaseEnsureEmailIsVerified;
use Illuminate\Http\Request;

/**
 * Laravel's "verified" middleware, skipped while the panel cannot send the
 * verification email (see EmailVerification).
 */
class EnsureEmailIsVerified extends BaseEnsureEmailIsVerified
{
    /**
     * @param  Request  $request
     * @param  string|null  $redirectToRoute
     * @return mixed
     */
    public function handle($request, Closure $next, $redirectToRoute = null)
    {
        if ($request->user() !== null && ! EmailVerification::isRequired()) {
            return $next($request);
        }

        return parent::handle($request, $next, $redirectToRoute);
    }
}
