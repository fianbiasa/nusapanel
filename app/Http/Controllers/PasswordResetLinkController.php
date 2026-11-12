<?php

namespace App\Http\Controllers;

use App\Http\Responses\PasswordResetLinkResponse;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController as FortifyPasswordResetLinkController;
use Laravel\Fortify\Http\Requests\SendPasswordResetLinkRequest;

/**
 * Fortify's password reset link request, limited per address and per
 * email, so nobody can flood an inbox with reset mail or try many emails
 * quickly. Over the limit, the answer is the same as always and no mail
 * is sent.
 */
class PasswordResetLinkController extends FortifyPasswordResetLinkController
{
    /**
     * Requests per IP address, and per email, in ten minutes.
     */
    public const PER_IP = 10;

    public const PER_EMAIL = 3;

    public function store(SendPasswordResetLinkRequest $request): Responsable
    {
        $keys = [
            'password-reset-ip:'.$request->ip() => self::PER_IP,
            'password-reset-email:'.Str::lower((string) $request->input('email')) => self::PER_EMAIL,
        ];

        foreach ($keys as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                return new PasswordResetLinkResponse;
            }
        }

        foreach (array_keys($keys) as $key) {
            RateLimiter::hit($key, 600);
        }

        return parent::store($request);
    }
}
