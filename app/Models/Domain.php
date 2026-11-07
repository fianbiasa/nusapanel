<?php

namespace App\Models;

use App\Enums\DomainStatus;
use App\Enums\SslStatus;
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
 * gives the Unicode form of internationalized names. `user_id`, `status`
 * and the SSL columns are deliberately not mass assignable.
 *
 * `ssl_status` is null while the domain has no Let's Encrypt certificate;
 * `force_https` is only ever true while the certificate is active.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property-read string $display_name
 * @property string $php_version
 * @property bool $is_primary
 * @property DomainStatus $status
 * @property SslStatus|null $ssl_status
 * @property list<string>|null $ssl_names
 * @property Carbon|null $ssl_expires_at
 * @property string|null $ssl_error
 * @property bool $force_https
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'php_version', 'is_primary'])]
class Domain extends Model
{
    /** @use HasFactory<DomainFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'force_https' => false,
    ];

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
            'ssl_status' => SslStatus::class,
            'ssl_names' => 'array',
            'ssl_expires_at' => 'datetime',
            'force_https' => 'boolean',
        ];
    }

    /**
     * The domain an agent task changed: the task's account's domain named
     * in its payload.
     */
    public static function findForTask(Task $task): ?self
    {
        $name = $task->payload['domain'] ?? null;

        if ($task->user_id === null || ! is_string($name)) {
            return null;
        }

        return static::query()
            ->where('user_id', $task->user_id)
            ->where('name', $name)
            ->first();
    }

    /**
     * Whether the website is served over HTTPS with its own certificate.
     */
    public function hasActiveSsl(): bool
    {
        return $this->ssl_status === SslStatus::Active;
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
