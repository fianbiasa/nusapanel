<?php

namespace App\Models;

use Database\Factories\ProtectedDirectoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A folder of a website that asks for a username and password before
 * anything in it is served. The agent writes its users into a file both
 * web servers check, so a change applies once the website has been applied
 * again. An alias pointer protects the same folders as its domain.
 *
 * @property int $id
 * @property int $domain_id
 * @property string $path "/" for the whole website, or a folder below its document root like "/admin".
 * @property string $realm What the browser shows when it asks for the login.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['path', 'realm'])]
class ProtectedDirectory extends Model
{
    /** @use HasFactory<ProtectedDirectoryFactory> */
    use HasFactory;

    /**
     * The website the folder belongs to.
     *
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * The logins that may open the folder.
     *
     * @return HasMany<ProtectedDirectoryUser, $this>
     */
    public function logins(): HasMany
    {
        return $this->hasMany(ProtectedDirectoryUser::class);
    }
}
