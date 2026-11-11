<?php

namespace App\Http\Controllers;

use App\Models\MailDomain;
use App\Services\MailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class MailSslController extends Controller
{
    public function __construct(private MailService $mail) {}

    /**
     * Ask a certificate for mail.<domain> again, after mail.<domain> was
     * pointed at this server.
     */
    public function __invoke(Request $request, MailDomain $mailDomain): RedirectResponse
    {
        Gate::authorize('update', $mailDomain);

        $this->mail->ensureAccountIsActive($mailDomain->domain->account, 'ssl');
        $this->mail->requestMailSsl($mailDomain, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('A certificate for mail.:domain is being requested.', ['domain' => $mailDomain->domain->name])]);

        return back();
    }
}
