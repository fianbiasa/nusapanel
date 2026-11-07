<?php

namespace App\Models;

use App\Enums\DomainStatus;
use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A website of a hosting account, served from
 * /home/<username>/domains/<name>/public_html.
 *
 * `name` is always stored in its ASCII (punycode) form; `display_name`
 * gives the Unicode form of internationalized names. `user_id` and
 * `status` are deliberately not mass assignable.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property-read string $display_name
 * @property string $php_version
 * @property bool $is_primary
 * @property DomainStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'php_version', 'is_primary'])]
class Domain extends Model
{
    /** @use HasFactory<DomainFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'status' => DomainStatus::class,
        ];
    }

    /**
     * The hosting account the domain belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The name as people type it, e.g. "bücher.de" for "xn--bcher-kva.de".
     *
     * @return Attribute<string, never>
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(function (): string {
            $unicode = idn_to_utf8($this->name, IDNA_NONTRANSITIONAL_TO_UNICODE);

            return $unicode === false ? $this->name : $unicode;
        });
    }
}
