<?php

namespace App\Events;

use App\Models\Task;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired once a task has been marked as succeeded or failed, so that the
 * panel can apply the agent's result to its own records.
 */
class AgentTaskFinished
{
    use Dispatchable;

    public function __construct(public Task $task) {}
}
