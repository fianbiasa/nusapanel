<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One call to the server agent, e.g. "create this domain".
 *
 * The payload is encrypted at rest and hidden from serialization because it
 * can contain secrets such as a new database password.
 *
 * @property string $id
 * @property int|null $user_id
 * @property int|null $actor_id
 * @property string|null $parent_id
 * @property string $action
 * @property array<string, mixed>|null $payload
 * @property TaskStatus $status
 * @property int $progress
 * @property int $attempts
 * @property array<string, mixed>|null $output
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['user_id', 'actor_id', 'parent_id', 'action', 'payload', 'status'])]
#[Hidden(['payload'])]
class Task extends Model
{
    use HasUuids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'queued',
        'progress' => 0,
        'attempts' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'output' => 'array',
            'status' => TaskStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * The hosting account this task changes, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The account that requested this task, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function markRunning(): void
    {
        $this->forceFill([
            'status' => TaskStatus::Running,
            'attempts' => $this->attempts + 1,
            'started_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $output
     */
    public function markSucceeded(array $output): void
    {
        $this->forceFill([
            'status' => TaskStatus::Success,
            'progress' => 100,
            'output' => $output,
            'error' => null,
            'finished_at' => now(),
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => TaskStatus::Failed,
            'error' => Str::limit($error, 2000),
            'finished_at' => now(),
        ])->save();
    }
}
