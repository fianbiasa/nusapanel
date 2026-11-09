<?php

namespace App\Models;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Enums\PointerMode;
use App\Enums\SslSource;
use App\Enums\SslStatus;
use App\Support\PhpSettings;
use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A website of a hosting account. Domains and subdomains are served from
 * /home/<username>/domains/<name>/public_html; a pointer shows the website
 * of its parent domain or redirects to it ({@see PointerMode}). Subdomains
 * and pointers belong to a domain of the same account, their parent, and
 * are removed together with it.
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
 * @property int|null $parent_id
 * @property string $name
 * @property DomainType $type
 * @property PointerMode|null $pointer_mode Only set for pointers.
 * @property-read string $display_name
 * @property string $php_version
 * @property array<string, string>|null $php_settings The php.ini settings that differ from the defaults ({@see PhpSettings}).
 * @property bool $is_primary
 * @property DomainStatus $status
 * @property SslStatus|null $ssl_status
 * @property SslSource|null $ssl_source Where the active certificate came from.
 * @property list<string>|null $ssl_names
 * @property Carbon|null $ssl_expires_at
 * @property string|null $ssl_issuer Who signed a custom certificate.
 * @property bool|null $ssl_trusted Whether browsers trust a custom certificate.
 * @property string|null $ssl_error
 * @property bool $force_https
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'type', 'pointer_mode', 'php_version', 'is_primary'])]
class Domain extends Model
{
    /** @use HasFactory<DomainFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'domain',
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
            'php_settings' => 'array',
            'type' => DomainType::class,
            'pointer_mode' => PointerMode::class,
            'status' => DomainStatus::class,
            'ssl_status' => SslStatus::class,
            'ssl_source' => SslSource::class,
            'ssl_trusted' => 'boolean',
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
     * Whether the website is served over HTTPS with its own certificate,
     * also while a new custom certificate replaces it.
     */
    public function hasActiveSsl(): bool
    {
        return in_array($this->ssl_status, [SslStatus::Active, SslStatus::Replacing], true);
    }

    /**
     * Whether the website serves a folder of its own: domains and
     * subdomains do, pointers never.
     */
    public function hasOwnFolder(): bool
    {
        return $this->type !== DomainType::Pointer;
    }

    /**
     * Whether the website may have URL redirects: every website except a
     * pointer that redirects everything to its parent anyway.
     */
    public function canHaveRedirects(): bool
    {
        return $this->pointer_mode !== PointerMode::Redirect;
    }

    /**
     * The domain a subdomain or pointer belongs to.
     *
     * @return BelongsTo<Domain, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * The subdomains and pointers of a domain.
     *
     * @return HasMany<Domain, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * The website's URL redirects.
     *
     * @return HasMany<UrlRedirect, $this>
     */
    public function urlRedirects(): HasMany
    {
        return $this->hasMany(UrlRedirect::class);
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
