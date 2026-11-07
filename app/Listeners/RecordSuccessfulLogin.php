<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Login;

class RecordSuccessfulLogin
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $event->user->forceFill(['last_login_at' => now()])->saveQuietly();

        $this->audit->record('auth.login', $event->user, actor: $event->user);
    }
}
