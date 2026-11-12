<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCertificateRequestRequest;
use App\Http\Requests\StoreSelfSignedCertificateRequest;
use App\Models\Domain;
use App\Services\CertificateRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Certificate signing requests (CSR) and self-signed certificates for the
 * signed-in account's websites.
 */
class CertificateRequestController extends Controller
{
    public function __construct(private CertificateRequestService $requests) {}

    /**
     * Make a new key and CSR for the website.
     */
    public function store(StoreCertificateRequestRequest $request, Domain $domain): RedirectResponse
    {
        $this->requests->create(
            $domain,
            $request->subject(),
            $request->string('key_type')->value(),
            $request->boolean('www'),
            $request->boolean('wildcard'),
            $request->user(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The CSR for :domain is ready. Copy it to your certificate authority.', ['domain' => $domain->display_name]),
        ]);

        return back();
    }

    /**
     * Forget the website's CSR and its key.
     */
    public function destroy(Request $request, Domain $domain): RedirectResponse
    {
        Gate::authorize('update', $domain);

        $this->requests->delete($domain, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The CSR for :domain was deleted.', ['domain' => $domain->display_name]),
        ]);

        return back();
    }

    /**
     * Make a self-signed certificate for the website and install it.
     */
    public function selfSigned(StoreSelfSignedCertificateRequest $request, Domain $domain): RedirectResponse
    {
        $this->requests->installSelfSigned(
            $domain,
            $request->subject(),
            $request->string('key_type')->value(),
            $request->boolean('www'),
            $request->boolean('wildcard'),
            $request->integer('days'),
            $request->user(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The self-signed certificate for :domain is being installed.', ['domain' => $domain->display_name]),
        ]);

        return back();
    }
}
