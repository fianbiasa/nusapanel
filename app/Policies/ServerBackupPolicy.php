<?php

namespace App\Policies;

use App\Models\ServerBackup;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may use a backup the server keeps. Administrators use every one; a
 * reseller those of the accounts it owned when they were made, also once
 * the account is deleted. Backups a reseller may not use are reported as
 * missing, so their existence is not revealed.
 */
class ServerBackupPolicy
{
    /**
     * Downloading it, and restoring it into its account or making its
     * deleted account again.
     */
    public function use(User $actor, ServerBackup $backup): Response
    {
        return $this->owns($actor, $backup) ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * Deleting it: a reseller only deletes backups it made by hand, never
     * those of the administrator's schedule.
     */
    public function delete(User $actor, ServerBackup $backup): Response
    {
        if (! $this->owns($actor, $backup)) {
            return Response::denyAsNotFound();
        }

        return $actor->isAdmin() || $backup->isManual()
            ? Response::allow()
            : Response::deny(__('Automatic backups are deleted by the administrator\'s rotation.'));
    }

    private function owns(User $actor, ServerBackup $backup): bool
    {
        return $actor->isAdmin() || ($actor->isReseller() && $backup->owner_id === $actor->id);
    }
}
