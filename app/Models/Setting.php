<?php

namespace App\Models;

use App\Services\ServerSettingsService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A server-wide setting the admin changes in the panel. Read and written
 * through {@see ServerSettingsService}, which knows each key's default.
 *
 * @property int $id
 * @property string $key
 * @property mixed $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
