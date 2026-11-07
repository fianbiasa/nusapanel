<?php

namespace App\Http\Controllers;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Show the dashboard. Admins also get live server information from the
     * agent, loaded as a deferred prop so a slow agent never blocks the page.
     */
    public function __invoke(Request $request, AgentClient $agent): Response
    {
        $props = [];

        if ($request->user()->isAdmin()) {
            $props['systemInfo'] = Inertia::defer(fn (): array => $this->systemInfo($agent));
        }

        return Inertia::render('dashboard', $props);
    }

    /**
     * @return array{data: array<string, mixed>|null, error: string|null}
     */
    private function systemInfo(AgentClient $agent): array
    {
        try {
            return ['data' => $agent->call('system.info'), 'error' => null];
        } catch (AgentException $exception) {
            report($exception);

            return ['data' => null, 'error' => __('The server agent could not be reached.')];
        }
    }
}
