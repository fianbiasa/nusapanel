<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    /**
     * List audit log entries, newest first.
     */
    public function index(): Response
    {
        $logs = AuditLog::query()
            ->with(['actor:id,username', 'impersonator:id,username'])
            ->latest('id')
            ->paginate(50)
            ->through(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor?->username,
                'impersonator' => $log->impersonator?->username,
                'subject' => $log->subject_type === null
                    ? null
                    : class_basename($log->subject_type).' #'.$log->subject_id,
                'ip_address' => $log->ip_address,
                'changes' => $log->changes,
                'created_at' => $log->created_at->toIso8601String(),
            ]);

        return Inertia::render('admin/audit-logs/index', [
            'logs' => $logs,
        ]);
    }
}
