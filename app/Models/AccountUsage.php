<?php

namespace App\Models;

use App\Services\QuotaService;
use Database\Factories\AccountUsageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How much disk space and how many files (inodes) a hosting account used
 * when the server last measured it. {@see QuotaService} refreshes it.
 *
 * @property int $id
 * @property int $user_id
 * @property int $disk_kb
 * @property int $inodes
 * @property Carbon $measured_at
 */
#[Table('account_usage', timestamps: false)]
#[Fillable(['disk_kb', 'inodes', 'measured_at'])]
class AccountUsage extends Model
{
    /** @use HasFactory<AccountUsageFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'disk_kb' => 'integer',
            'inodes' => 'integer',
            'measured_at' => 'datetime',
        ];
    }

    /**
     * The disk space used, in whole megabytes rounded up.
     */
    public function diskMegabytes(): int
    {
        return (int) ceil($this->disk_kb / 1024);
    }

    /**
     * The hosting account that was measured.
     *
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
