<?php

namespace App\Http\Controllers;

use App\Enums\MailDomainMode;
use App\Enums\MailProvider;
use App\Http\Requests\UpdateMailModeRequest;
use App\Models\Domain;
use App\Services\MailService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class MailModeController extends Controller
{
    public function __construct(private MailService $mail) {}

    /**
     * Have this server take a domain's mail, or another service.
     */
    public function __invoke(UpdateMailModeRequest $request, Domain $domain): RedirectResponse
    {
        $mode = MailDomainMode::from((string) $request->validated('mode'));
        $provider = MailProvider::tryFrom((string) $request->validated('provider'));

        $dnsChanged = $this->mail->setDomainMode($domain, $mode, $provider, $request->user());

        $replace = ['domain' => $domain->name];

        $message = match (true) {
            $mode === MailDomainMode::Local => $dnsChanged
                ? __('This server takes mail for :domain again, and its MX record points here again.', $replace)
                : __('This server takes mail for :domain again. Point its MX record here if it does not yet.', $replace),
            $provider?->managesDns() === true => __('Mail for :domain now goes to :service; its MX and SPF records were changed.', [
                ...$replace,
                'service' => $provider === MailProvider::Google ? 'Google Workspace' : 'Zoho Mail',
            ]),
            default => __('Mail for :domain now goes to another service. Set its MX and SPF records there in DNS.', $replace),
        };

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
