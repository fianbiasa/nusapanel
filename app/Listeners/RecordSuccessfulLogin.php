<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ImpersonationService;
use Illuminate\Auth\Events\Login;

class RecordSuccessfulLogin
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Login $event): void
    {
        // Signing in as another account ("login as") or back is not a login
        // of that account.
        if (! $event->user instanceof User || request()->attributes->get(ImpersonationService::SWITCHING) === true) {
            return;
        }

        $event->user->forceFill(['last_login_at' => now()])->saveQuietly();

        $this->audit->record('auth.login', $event->user, actor: $event->user);
    }
}
