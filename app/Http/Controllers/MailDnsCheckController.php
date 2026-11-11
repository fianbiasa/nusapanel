<?php

namespace App\Http\Controllers;

use App\Models\MailDomain;
use App\Services\MailDnsCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Whether public DNS has a mail domain's records as this server needs
 * them (MX, SPF, DKIM, DMARC), for the Email page's "Cek DNS".
 */
class MailDnsCheckController extends Controller
{
    public function __invoke(Request $request, MailDomain $mailDomain, MailDnsCheckService $checks): JsonResponse
    {
        Gate::authorize('update', $mailDomain);

        return response()->json($checks->check($mailDomain, $request->boolean('fresh')));
    }
}
