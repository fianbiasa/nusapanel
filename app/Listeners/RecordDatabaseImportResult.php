<?php

namespace App\Listeners;

use App\Enums\TaskStatus;
use App\Events\AgentTaskFinished;
use App\Models\Database;
use Illuminate\Support\Str;

/**
 * Keep how an import into a database went: done, or failed with what
 * MariaDB said (about the account's own file, so the account sees it).
 */
class RecordDatabaseImportResult
{
    public function handle(AgentTaskFinished $event): void
    {
        $task = $event->task;
        $name = $task->payload['database'] ?? null;

        if ($task->action !== 'db.import' || $task->user_id === null || ! is_string($name)) {
            return;
        }

        $database = Database::query()->where('user_id', $task->user_id)->where('name', $name)->first();

        if ($database === null || $database->import_status !== 'running') {
            return;
        }

        if ($task->status === TaskStatus::Success) {
            $database->forceFill(['import_status' => 'done', 'import_error' => null, 'imported_at' => now()])->save();
        } elseif ($task->status === TaskStatus::Failed) {
            $database->forceFill([
                'import_status' => 'failed',
                'import_error' => Str::limit((string) $task->error, 500),
                'imported_at' => now(),
            ])->save();
        }
    }
}
