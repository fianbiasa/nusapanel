<?php

namespace App\Policies;

use App\Models\CronJob;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CronJobPolicy
{
    /**
     * Accounts may only change their own cron jobs. Other accounts' jobs are
     * reported as missing so their existence is not revealed.
     */
    public function update(User $user, CronJob $cronJob): Response
    {
        return $this->own($user, $cronJob);
    }

    /**
     * Accounts may only delete their own cron jobs.
     */
    public function delete(User $user, CronJob $cronJob): Response
    {
        return $this->own($user, $cronJob);
    }

    private function own(User $user, CronJob $cronJob): Response
    {
        return $cronJob->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
