<?php

namespace App\Models;

use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A message to many accounts at once. An administrator's goes to every
 * reseller and/or hosting account (audience: roles); a reseller's to its
 * own hosting accounts. Recipients see it on their dashboard, and by email
 * when it was emailed.
 *
 * @property int $id
 * @property int $sender_id
 * @property list<string> $audience The roles it is for: reseller, user.
 * @property string $subject
 * @property string $body
 * @property bool $emailed
 * @property int $recipient_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['audience', 'subject', 'body', 'emailed'])]
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'emailed' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
