<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class RecordFailedLogin
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Record the attempted login name only; the password is never stored.
     */
    public function handle(Failed $event): void
    {
        $attempted = $event->credentials[Fortify::username()] ?? $event->credentials['email'] ?? '';

        $this->audit->record(
            'auth.failed',
            $event->user instanceof User ? $event->user : null,
            ['username' => Str::limit((string) $attempted, 255, '')],
        );
    }
}
