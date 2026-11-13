<?php

namespace App\Http\Controllers\Admin;

use App\Agent\AgentException;
use App\Http\Controllers\Controller;
use App\Services\ProcessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProcessController extends Controller
{
    public function __construct(private ProcessService $processes) {}

    /**
     * The server's processes, busiest first, and what each account uses.
     */
    public function index(): Response
    {
        return Inertia::render('admin/processes/index', [
            'snapshot' => function (): array {
                try {
                    return ['result' => $this->processes->list(), 'error' => null];
                } catch (AgentException $exception) {
                    report($exception);

                    return ['result' => null, 'error' => __('The agent did not answer. Check that rayxod is running: systemctl status rayxod')];
                }
            },
            'signals' => ProcessService::SIGNALS,
        ]);
    }

    /**
     * Stop a process: TERM asks it to end, KILL ends it at once.
     */
    public function kill(Request $request): RedirectResponse
    {
        $process = $request->validate([
            'pid' => ['required', 'integer', 'min:2'],
            'start' => ['required', 'string', 'regex:/^\d{1,20}$/'],
            'signal' => ['required', Rule::in(ProcessService::SIGNALS)],
            'user' => ['required', 'string', 'max:64'],
            'command' => ['required', 'string', 'max:600'],
        ]);

        $this->processes->kill($process, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Process :pid was sent :signal.', [
            'pid' => $process['pid'],
            'signal' => $process['signal'],
        ])]);

        return back();
    }
}
