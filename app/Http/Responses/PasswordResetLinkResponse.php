<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The answer to a password reset request, the same whether the email
 * belongs to an account, belongs to none, or was asked for too often: a
 * different answer would tell anyone which emails have accounts here.
 */
class PasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public function __construct(public string $status = '') {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        $message = __('If an account uses that email, a link to reset its password has been sent to it.');

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message], 200)
            : back()->with('status', $message);
    }
}
