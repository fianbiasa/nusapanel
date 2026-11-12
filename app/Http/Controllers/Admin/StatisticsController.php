<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\UsageStatisticsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Usage statistics: bandwidth and disk space over the last 30 days, this
 * month's totals, and the accounts that use the most. Administrators see
 * every hosting account (under /admin), resellers their own (under
 * /reseller).
 */
class StatisticsController extends Controller
{
    public function __construct(private UsageStatisticsService $statistics) {}

    public function index(Request $request): Response
    {
        $accounts = $this->statistics->accountsOf($request->user());

        return Inertia::render('admin/statistics/index', [
            'scope' => $request->user()->isReseller() ? 'reseller' : 'admin',
            'totals' => $this->statistics->totals($accounts),
            'history' => $this->statistics->history($accounts),
            'topBandwidth' => $this->statistics->topBandwidth($accounts),
            'topDisk' => $this->statistics->topDisk($accounts),
        ]);
    }
}
