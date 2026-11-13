<?php

namespace App\Models;

use Database\Factories\ResellerSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How a reseller presents the panel to its hosting accounts, and the
 * nameservers their zones name. Every value is null while the reseller
 * keeps the panel's own.
 *
 * `user_id`, `logo_path` and the login domain's status are deliberately
 * not mass assignable.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $brand_name
 * @property string|null $brand_color "#rrggbb"
 * @property string|null $logo_path Where the logo is on the local disk.
 * @property list<string>|null $nameservers
 * @property string|null $login_domain The domain the reseller and its accounts sign in at.
 * @property string|null $login_domain_status "pending", "active" or "failed".
 * @property string|null $login_domain_error Why the login domain could not be set up.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['brand_name', 'brand_color', 'nameservers'])]
class ResellerSetting extends Model
{
    /** @use HasFactory<ResellerSettingFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nameservers' => 'array',
        ];
    }

    /**
     * The reseller.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
