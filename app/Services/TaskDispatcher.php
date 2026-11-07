<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Jobs\RunAgentTask;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class TaskDispatcher
{
    /**
     * Record an agent action as a task and queue it for the agent.
     *
     * @param  string  $action  Agent method, e.g. "web.apply_domain".
     * @param  array<string, mixed>  $payload  Parameters for the agent; stored encrypted.
     * @param  User|null  $account  The hosting account the action changes.
     * @param  User|null  $actor  Who requested it; defaults to the signed-in account.
     */
    public function dispatch(string $action, array $payload = [], ?User $account = null, ?User $actor = null): Task
    {
        $actor ??= Auth::user() instanceof User ? Auth::user() : null;

        $task = Task::create([
            'user_id' => $account?->id,
            'actor_id' => $actor?->id,
            'action' => $action,
            'payload' => $payload,
            'status' => TaskStatus::Queued,
        ]);

        RunAgentTask::dispatch($task)->afterCommit();

        return $task;
    }
}
