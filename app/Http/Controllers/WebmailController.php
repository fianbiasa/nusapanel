<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Services\WebmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class WebmailController extends Controller
{
    public function __construct(private WebmailService $webmail) {}

    /**
     * Open webmail for one of the signed-in account's mailboxes. The page
     * posts the ticket to the returned URL; the cookie binds it to this
     * browser. Cookies ignore ports, so Roundcube (port 443) receives a
     * cookie the panel set for /webmail/.
     *
     * @throws ValidationException
     */
    public function __invoke(Request $request, Mailbox $mailbox): JsonResponse
    {
        Gate::authorize('view', $mailbox);

        $session = $this->webmail->open($mailbox, $request->user());

        return response()
            ->json(['url' => $session['url'], 'ticket' => $session['ticket']])
            ->header('Cache-Control', 'no-store')
            ->withCookie(cookie(
                WebmailService::BINDING_COOKIE,
                $session['binding'],
                minutes: 2,
                path: '/webmail/',
                secure: true,
                httpOnly: true,
                sameSite: 'strict',
            ));
    }
}
