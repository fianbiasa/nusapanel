<?php

namespace App\Http\Controllers\Admin;

use App\Agent\AgentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePhpUploadLimitsRequest;
use App\Http\Requests\Admin\UpdateServerTimezoneRequest;
use App\Services\DomainService;
use App\Services\ServerSettingsService;
use App\Support\PhpSettings;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The server's own settings: its hostname and the certificate the panel,
 * phpMyAdmin and FTP serve, its timezone, and the upload sizes websites
 * may use.
 */
class ServerSettingController extends Controller
{
    public function __construct(
        private ServerSettingsService $settings,
        private DomainService $domains,
    ) {}

    public function index(): Response
    {
        $server = null;
        $error = null;

        try {
            $server = $this->settings->serverState();
        } catch (AgentException $exception) {
            report($exception);
            $error = __('The agent did not answer. Check that rayxod is running: systemctl status rayxod');
        }

        return Inertia::render('admin/server-settings/index', [
            'server' => $server,
            'error' => $error,
            'certificate_request' => $this->settings->hostnameCertificateRequest(),
            'timezones' => DateTimeZone::listIdentifiers(),
            'php_uploads' => [
                'limit' => $this->settings->phpUploadLimit(),
                'default' => $this->settings->phpUploadDefault(),
                'sizes' => PhpSettings::SIZES,
            ],
        ]);
    }

    /**
     * Change the server's timezone.
     *
     * @throws ValidationException
     */
    public function updateTimezone(UpdateServerTimezoneRequest $request): RedirectResponse
    {
        $timezone = $request->string('timezone')->toString();

        try {
            $this->settings->setTimezone($timezone, $request->user());
        } catch (AgentException $exception) {
            report($exception);

            throw ValidationException::withMessages(['timezone' => __('The server could not change its timezone: :detail', ['detail' => $exception->getMessage()])]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The server now runs on :timezone time.', ['timezone' => $timezone])]);

        return back();
    }

    /**
     * Get the hostname's Let's Encrypt certificate again, in the background.
     *
     * @throws ValidationException
     */
    public function issueHostnameCertificate(Request $request): RedirectResponse
    {
        if ($this->settings->hostnameCertificateRequest()['pending']) {
            throw ValidationException::withMessages(['certificate' => __('The certificate is already being requested.')]);
        }

        $this->settings->requestHostnameCertificate($request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The hostname certificate is being requested. This takes up to a minute.')]);

        return back();
    }

    /**
     * Change the upload sizes websites may use; every website is applied
     * again when they change.
     */
    public function updatePhpUploads(UpdatePhpUploadLimitsRequest $request): RedirectResponse
    {
        $changed = $this->settings->setPhpUploads(
            $request->string('limit')->toString(),
            $request->string('default')->toString(),
            $request->user(),
        );

        $message = __('Nothing changed.');

        if ($changed) {
            $message = __('Upload sizes saved; :count websites are being updated.', ['count' => $this->domains->reapplyAll($request->user())]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
