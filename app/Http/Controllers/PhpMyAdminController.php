<?php

namespace App\Http\Controllers;

use App\Services\PhpMyAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PhpMyAdminController extends Controller
{
    public function __construct(private PhpMyAdminService $phpMyAdmin) {}

    /**
     * Open phpMyAdmin for the signed-in account. The page posts the ticket
     * to the returned URL; the cookie binds it to this browser. Cookies
     * ignore ports, so phpMyAdmin (port 443) receives a cookie the panel set
     * for /phpmyadmin/.
     *
     * @throws ValidationException
     */
    public function __invoke(Request $request): JsonResponse
    {
        $account = $request->user();
        $session = $this->phpMyAdmin->open($account, $account);

        return response()
            ->json(['url' => $session['url'], 'ticket' => $session['ticket']])
            ->header('Cache-Control', 'no-store')
            ->withCookie(cookie(
                PhpMyAdminService::BINDING_COOKIE,
                $session['binding'],
                minutes: 2,
                path: '/phpmyadmin/',
                secure: true,
                httpOnly: true,
                sameSite: 'strict',
            ));
    }
}
