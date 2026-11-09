<?php

namespace App\Listeners;

use App\Enums\DatabaseStatus;
use App\Enums\TaskStatus;
use App\Events\AgentTaskFinished;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\Task;

/**
 * Bring a database or database user's panel record in line with what the
 * agent did on the server. The task's error is shown in the task list.
 * Results that arrive after the record has moved on are ignored.
 */
class ApplyDatabaseTaskResult
{
    public function handle(AgentTaskFinished $event): void
    {
        $task = $event->task;

        $record = match ($task->action) {
            'db.create_database', 'db.delete_database' => $this->find(Database::class, $task, 'database'),
            'db.apply_user', 'db.delete_user' => $this->find(DatabaseUser::class, $task, 'user'),
            default => null,
        };

        if ($record === null) {
            return;
        }

        $removal = in_array($task->action, ['db.delete_database', 'db.delete_user'], true);
        $expected = $removal ? DatabaseStatus::Deleting : DatabaseStatus::Pending;

        if ($record->status !== $expected) {
            return;
        }

        if ($task->status === TaskStatus::Success) {
            $removal ? $record->delete() : $record->forceFill(['status' => DatabaseStatus::Active])->save();
        } elseif ($task->status === TaskStatus::Failed) {
            $record->forceFill(['status' => DatabaseStatus::Failed])->save();
        }
    }

    /**
     * The record of the task's account named in its payload.
     *
     * @param  class-string<Database>|class-string<DatabaseUser>  $model
     */
    private function find(string $model, Task $task, string $key): Database|DatabaseUser|null
    {
        $name = $task->payload[$key] ?? null;

        if ($task->user_id === null || ! is_string($name)) {
            return null;
        }

        return $model::query()->where('user_id', $task->user_id)->where('name', $name)->first();
    }
}
