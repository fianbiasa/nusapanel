<?php

namespace App\Models;

use Database\Factories\CronJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A cron job of a hosting account: a schedule and a shell command, run by
 * the cron daemon as the account's Linux user while it is enabled.
 *
 * @property int $id
 * @property int $user_id
 * @property string $schedule Five cron fields, or an @ schedule such as @daily.
 * @property string $command One line, run by bash in the account's home.
 * @property string|null $description
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['schedule', 'command', 'description', 'enabled'])]
class CronJob extends Model
{
    /** @use HasFactory<CronJobFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * The hosting account the job belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
