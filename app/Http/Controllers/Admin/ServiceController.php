<?php

namespace App\Http\Controllers\Admin;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\RebuildService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The system services the server runs on: their state, start/stop/restart
 * and reload, and what the agent's watchdog did. Which services exist and
 * what may be done to them is decided by the agent.
 */
class ServiceController extends Controller
{
    public function __construct(
        private AgentClient $agent,
        private AuditLogger $audit,
    ) {}

    /**
     * The state of every service, the hosting accounts' PHP pools and the
     * watchdog's latest events.
     */
    public function index(): Response
    {
        $report = null;
        $error = null;

        try {
            $report = $this->agent->call('service.status', [], 20);
        } catch (AgentException $exception) {
            report($exception);
            $error = __('The agent did not answer. Check that rayxod is running: systemctl status rayxod');
        }

        return Inertia::render('admin/services/index', [
            'report' => $report,
            'error' => $error,
        ]);
    }

    /**
     * Start, stop, restart or reload a service.
     *
     * @throws ValidationException
     */
    public function act(string $unit, string $action): RedirectResponse
    {
        try {
            $this->agent->call('service.action', ['unit' => $unit, 'action' => $action], 120);
        } catch (AgentException $exception) {
            if ($exception->reason === null) {
                report($exception);
            }

            throw ValidationException::withMessages(['service' => match ($exception->reason) {
                'not_allowed' => __('This action is not allowed for :service.', ['service' => $unit]),
                'config_invalid' => __('The configuration has an error, so :service was left as it is: :detail', [
                    'service' => $unit,
                    'detail' => $exception->getMessage(),
                ]),
                'unknown_service' => __('Unknown service.'),
                default => __(':service could not be changed. Try again in a moment.', ['service' => $unit]),
            }]);
        }

        $this->audit->record('service.'.$action, null, ['unit' => $unit]);

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($action) {
            'start' => __(':service is being started.', ['service' => $unit]),
            'stop' => __(':service is stopped and no longer starts at boot.', ['service' => $unit]),
            'reload' => __(':service is reloading its configuration.', ['service' => $unit]),
            default => __(':service is being restarted.', ['service' => $unit]),
        }]);

        return back();
    }

    /**
     * Write every website, quota, crontab and FTP folder again from the
     * database, as "rayxo rebuild" does on the server.
     */
    public function rebuild(Request $request, RebuildService $rebuild): RedirectResponse
    {
        $queued = $rebuild->queueAll($request->user());

        $this->audit->record('server.rebuilt', null, $queued);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rewriting :websites websites, :quotas quotas, :crontabs crontabs and :ftp FTP folders. Follow them under Tasks.', [
            'websites' => $queued['websites'],
            'quotas' => $queued['quotas'],
            'crontabs' => $queued['crontabs'],
            'ftp' => $queued['ftp_folders'],
        ])]);

        return back();
    }
}
