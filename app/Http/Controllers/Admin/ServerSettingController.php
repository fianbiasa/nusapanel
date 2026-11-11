<?php

namespace App\Http\Controllers\Admin;

use App\Agent\AgentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TryPanelPortRequest;
use App\Http\Requests\Admin\UpdateMailSendLimitsRequest;
use App\Http\Requests\Admin\UpdateNameserversRequest;
use App\Http\Requests\Admin\UpdatePhpUploadLimitsRequest;
use App\Http\Requests\Admin\UpdateServerHostnameRequest;
use App\Http\Requests\Admin\UpdateServerTimezoneRequest;
use App\Services\DnsService;
use App\Services\DomainService;
use App\Services\MailSendLimitService;
use App\Services\ServerSettingsService;
use App\Support\PhpSettings;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The server's own settings: its hostname (which can be changed) and the
 * certificate the panel, phpMyAdmin and FTP serve, its timezone, and the
 * upload sizes websites may use.
 */
class ServerSettingController extends Controller
{
    public function __construct(
        private ServerSettingsService $settings,
        private DomainService $domains,
        private DnsService $dns,
        private MailSendLimitService $sendLimits,
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
            'nameservers' => $this->dns->nameservers(),
            'public_ip' => config('hosting.public_ip') ?: null,
            'mail_limits' => [
                ...$this->settings->mailSendLimits(),
                'default_account' => ServerSettingsService::DEFAULT_MAIL_SEND_LIMIT_ACCOUNT,
            ],
        ]);
    }

    /**
     * Change the nameservers every DNS zone names, and write every zone
     * again.
     */
    public function updateNameservers(UpdateNameserversRequest $request): RedirectResponse
    {
        $nameservers = array_values(array_filter([
            $request->string('ns1')->toString(),
            $request->filled('ns2') ? $request->string('ns2')->toString() : null,
        ]));

        $queued = $this->dns->setNameservers($nameservers, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The nameservers are saved; :count DNS zones are being rewritten.', ['count' => $queued])]);

        return back();
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
     * Give the server a new hostname, in the background.
     *
     * @throws ValidationException
     */
    public function updateHostname(UpdateServerHostnameRequest $request): RedirectResponse
    {
        $hostname = $request->string('hostname')->toString();

        try {
            $this->settings->changeHostname($hostname, $request->user());
        } catch (AgentException $exception) {
            if ($exception->reason === null) {
                report($exception);
            }

            throw ValidationException::withMessages(['hostname' => $exception->reason === 'busy'
                ? __('The hostname is already being changed.')
                : __('The hostname cannot be changed: :detail', ['detail' => $exception->getMessage()])]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Changing the hostname to :hostname. This takes a few minutes; follow it on this page.', ['hostname' => $hostname])]);

        return back();
    }

    /**
     * Open a port for the panel next to its own, to be confirmed through it.
     *
     * @throws ValidationException
     */
    public function tryPort(TryPanelPortRequest $request): RedirectResponse
    {
        $port = $request->integer('port');

        try {
            $this->settings->tryPanelPort($port, $request->user());
        } catch (AgentException $exception) {
            throw $this->portError($exception);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Port :port is open for 5 minutes. Open the panel through it to confirm.', ['port' => $port])]);

        return back();
    }

    /**
     * Make the port being tried the panel's. Only a request that came in
     * through that port may, since it proves the port can be reached.
     *
     * @throws ValidationException
     */
    public function confirmPort(Request $request): RedirectResponse
    {
        $trial = $this->portTrial();

        if ($trial === null) {
            throw ValidationException::withMessages(['port' => __('No port is being tried.')]);
        }

        if ($request->getPort() !== $trial['port']) {
            throw ValidationException::withMessages(['port' => __('Open the panel through port :port to confirm it: that shows the port can be reached.', ['port' => $trial['port']])]);
        }

        try {
            $this->settings->confirmPanelPort($trial['port'], $request->user());
        } catch (AgentException $exception) {
            throw $this->portError($exception);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Moving the panel to port :port. This takes a minute; follow it on this page.', ['port' => $trial['port']])]);

        return back();
    }

    /**
     * Close the port being tried.
     *
     * @throws ValidationException
     */
    public function cancelPort(Request $request): RedirectResponse
    {
        try {
            $this->settings->cancelPanelPort($request->user());
        } catch (AgentException $exception) {
            throw $this->portError($exception);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The port tried is closed again.')]);

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
     * Change the daily send limits of hosting accounts (empty: no limit);
     * the mail server gets them at once.
     */
    public function updateMailSendLimits(UpdateMailSendLimitsRequest $request): RedirectResponse
    {
        $changed = $this->settings->setMailSendLimits(
            $request->filled('account') ? $request->integer('account') : null,
            $request->filled('server') ? $request->integer('server') : null,
            $request->user(),
        );

        if ($changed) {
            $this->sendLimits->sync($request->user());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $changed ? __('Send limits saved.') : __('Nothing changed.')]);

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

    /**
     * The port being tried, as the agent reports it.
     *
     * @return array{port: int, confirmed: bool}|null
     */
    private function portTrial(): ?array
    {
        try {
            $trial = $this->settings->serverState()['port_trial'] ?? null;
        } catch (AgentException $exception) {
            throw $this->portError($exception);
        }

        if (! is_array($trial) || ! is_int($trial['port'] ?? null)) {
            return null;
        }

        return ['port' => $trial['port'], 'confirmed' => (bool) ($trial['confirmed'] ?? false)];
    }

    private function portError(AgentException $exception): ValidationException
    {
        if ($exception->reason === null && $exception->getCode() !== -32000 && $exception->getCode() !== -32602) {
            report($exception);
        }

        return ValidationException::withMessages(['port' => __('The panel port cannot be changed: :detail', ['detail' => $exception->getMessage()])]);
    }
}
