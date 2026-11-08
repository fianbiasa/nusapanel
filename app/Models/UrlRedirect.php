<?php

namespace App\Models;

use Database\Factories\UrlRedirectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A URL redirect of a website: visitors of `path` on the domain go to
 * `destination` with a 301 or 302. A wildcard redirect also redirects
 * everything under the path and keeps the rest of it. The agent writes the
 * redirects of a domain into its Nginx vhost, so they apply once the domain
 * has been applied again.
 *
 * @property int $id
 * @property int $domain_id
 * @property string $path "/" or a path like "/promo", without a trailing slash.
 * @property string $destination An absolute http:// or https:// URL.
 * @property int $status_code 301 or 302.
 * @property bool $wildcard
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['path', 'destination', 'status_code', 'wildcard'])]
class UrlRedirect extends Model
{
    /** @use HasFactory<UrlRedirectFactory> */
    use HasFactory;

    /**
     * The most redirects one website may have; the agent refuses more.
     */
    public const MAX_PER_DOMAIN = 100;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'wildcard' => 'boolean',
        ];
    }

    /**
     * The website the redirect belongs to.
     *
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * The redirect as the agent's `web.apply_domain` expects it.
     *
     * @return array{path: string, destination: string, status: int, wildcard: bool}
     */
    public function toAgentParams(): array
    {
        return [
            'path' => $this->path,
            'destination' => $this->destination,
            'status' => $this->status_code,
            'wildcard' => $this->wildcard,
        ];
    }
}
