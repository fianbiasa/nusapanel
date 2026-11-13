<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\LoginKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditLogger
{
    public function __construct(private Request $request) {}

    /**
     * Record an action in the append-only audit log. While an administrator
     * or reseller is signed in as another account ("login as"), the entry
     * names them as the impersonator.
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
            'impersonator_id' => $this->impersonatorId(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 500, ''),
            'changes' => $changes === [] ? null : $changes,
            ...$this->loginKey(),
        ]);
    }

    /**
     * The login key an API request came with, so the log shows which key
     * made the change.
     *
     * @return array{login_key_id?: int, login_key_name?: string}
     */
    private function loginKey(): array
    {
        $key = $this->request->attributes->get('login_key');

        return $key instanceof LoginKey ? ['login_key_id' => $key->id, 'login_key_name' => $key->name] : [];
    }

    /**
     * The account signed in as the current one in this session, if any.
     */
    private function impersonatorId(): ?int
    {
        if (! $this->request->hasSession()) {
            return null;
        }

        $id = $this->request->session()->get(ImpersonationService::SESSION_KEY);

        return is_numeric($id) ? (int) $id : null;
    }
}
