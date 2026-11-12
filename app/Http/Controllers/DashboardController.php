<?php

namespace App\Http\Controllers;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Task;
use App\Models\User;
use App\Services\UsageStatisticsService;
use App\Support\AccountLimits;
use App\Support\ResellerLimits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Show the dashboard. Admins get counts for the whole server, recent
     * activity and live server information from the agent (deferred, so a
     * slow agent never blocks the page); resellers get their accounts and
     * what they use and give out against their reseller package; hosting
     * accounts get their own domains and what their package allows.
     */
    public function __invoke(Request $request, AgentClient $agent, UsageStatisticsService $statistics): Response
    {
        $user = $request->user();
        $props = [];

        if ($user->isAdmin()) {
            $props['stats'] = $this->serverStats();
            $props['recentActivity'] = $this->recentActivity();
            $props['systemInfo'] = Inertia::defer(fn (): array => $this->systemInfo($agent));
        } elseif ($user->isReseller()) {
            $user->loadMissing('resellerPackage');

            $props['stats'] = ['accounts' => $this->countByStatus(User::query()->where('owner_id', $user->id)->where('role', Role::User))];
            $props['resellerLimits'] = ResellerLimits::for($user)->toArray();
        } elseif ($user->hasRole(Role::User)) {
            $props['stats'] = ['domains' => $this->countByStatus($user->domains()->getQuery())];
            $props['domains'] = $user->domains()
                ->orderByDesc('is_primary')
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (Domain $domain): array => [
                    'id' => $domain->id,
                    'name' => $domain->name,
                    'display_name' => $domain->display_name,
                    'status' => $domain->status->value,
                    'php_version' => $domain->php_version,
                    'is_primary' => $domain->is_primary,
                    'ssl_active' => $domain->hasActiveSsl(),
                ]);
            $props['limits'] = AccountLimits::for($user)->toArray();
            $props['usageHistory'] = $statistics->history([$user->id]);
        }

        return Inertia::render('dashboard', $props);
    }

    /**
     * @return array{accounts: array<string, int>, domains: array<string, int>, tasks: array{active: int, failed_24h: int, total_24h: int}}
     */
    private function serverStats(): array
    {
        $since = now()->subDay();

        return [
            'accounts' => $this->countByStatus(User::query()->where('role', Role::User)),
            'domains' => $this->countByStatus(Domain::query()),
            'tasks' => [
                'active' => Task::query()->whereIn('status', [TaskStatus::Queued, TaskStatus::Running])->count(),
                'failed_24h' => Task::query()->where('status', TaskStatus::Failed)->where('created_at', '>=', $since)->count(),
                'total_24h' => Task::query()->where('created_at', '>=', $since)->count(),
            ],
        ];
    }

    /**
     * The number of rows per status, plus their total.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return array<string, int>
     */
    private function countByStatus(Builder $query): array
    {
        $counts = $query->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        return ['total' => array_sum($counts), ...$counts];
    }

    /**
     * @return list<array{id: int, action: string, actor: string|null, created_at: string}>
     */
    private function recentActivity(): array
    {
        $logs = AuditLog::query()
            ->with('actor:id,username')
            ->latest('created_at')
            ->limit(6)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor?->username,
                'created_at' => $log->created_at->toIso8601String(),
            ]);

        return array_values($logs->all());
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
