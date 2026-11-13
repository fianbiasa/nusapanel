<?php

namespace App\Http\Resources;

use App\Models\Database;
use App\Models\DatabaseUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A MariaDB user of the account, with the databases it may use.
 *
 * @mixin DatabaseUser
 */
class DatabaseUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'databases' => $this->whenLoaded('databases', fn () => $this->databases->map(fn (Database $database): string => $database->name)->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
