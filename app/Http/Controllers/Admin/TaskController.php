<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    /**
     * List agent tasks, newest first. Payloads are never sent to the browser.
     */
    public function index(): Response
    {
        $tasks = Task::query()
            ->with(['account:id,username', 'actor:id,username'])
            ->latest()
            ->paginate(25)
            ->through(fn (Task $task): array => [
                'id' => $task->id,
                'action' => $task->action,
                'status' => $task->status->value,
                'progress' => $task->progress,
                'account' => $task->account?->username,
                'actor' => $task->actor?->username,
                'error' => $task->error,
                'created_at' => $task->created_at->toIso8601String(),
                'finished_at' => $task->finished_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/tasks/index', [
            'tasks' => $tasks,
        ]);
    }
}
