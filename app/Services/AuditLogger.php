<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditLogger
{
    public function __construct(private Request $request) {}

    /**
     * Record an action in the append-only audit log.
     *
     * Never put secrets (passwords, keys, tokens) in `$changes`.
     *
     * @param  array<string, mixed>  $changes
     */
    public function record(string $action, ?Model $subject = null, array $changes = [], ?User $actor = null): AuditLog
    {
        $actor ??= $this->request->user();

        return AuditLog::create([
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 500, ''),
            'changes' => $changes === [] ? null : $changes,
        ]);
    }
}
