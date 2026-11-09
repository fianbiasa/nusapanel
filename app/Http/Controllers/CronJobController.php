<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCronJobRequest;
use App\Http\Requests\UpdateCronJobRequest;
use App\Models\CronJob;
use App\Services\CronJobService;
use App\Support\AccountLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CronJobController extends Controller
{
    public function __construct(private CronJobService $cron) {}

    /**
     * List the signed-in account's cron jobs and whether the latest change
     * reached the server.
     */
    public function index(Request $request): Response
    {
        $account = $request->user();

        return Inertia::render('cron-jobs/index', [
            'cron_jobs' => $account->cronJobs()
                ->orderBy('id')
                ->get()
                ->map(fn (CronJob $job): array => [
                    'id' => $job->id,
                    'schedule' => $job->schedule,
                    'command' => $job->command,
                    'description' => $job->description,
                    'enabled' => $job->enabled,
                ]),
            'sync' => $this->cron->syncState($account),
            'home' => '/home/'.$account->username,
            'limits' => AccountLimits::for($account)->toArray(),
        ]);
    }

    /**
     * Add a cron job.
     */
    public function store(StoreCronJobRequest $request): RedirectResponse
    {
        $account = $request->user();

        $this->cron->create(
            $account,
            $request->string('schedule')->toString(),
            $request->string('command')->toString(),
            $request->filled('description') ? $request->string('description')->toString() : null,
            $account,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The cron job is being added.')]);

        return to_route('cron-jobs.index');
    }

    /**
     * Change, pause or resume a cron job.
     */
    public function update(UpdateCronJobRequest $request, CronJob $cronJob): RedirectResponse
    {
        $this->cron->update(
            $cronJob,
            $request->string('schedule')->toString(),
            $request->string('command')->toString(),
            $request->filled('description') ? $request->string('description')->toString() : null,
            $request->boolean('enabled'),
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The cron job is being updated.')]);

        return back();
    }

    /**
     * Remove a cron job.
     */
    public function destroy(Request $request, CronJob $cronJob): RedirectResponse
    {
        Gate::authorize('delete', $cronJob);

        $this->cron->delete($cronJob, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The cron job is being deleted.')]);

        return back();
    }
}
