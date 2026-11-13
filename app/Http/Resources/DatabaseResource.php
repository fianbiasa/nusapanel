<?php

namespace App\Http\Resources;

use App\Models\Database;
use App\Models\DatabaseUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A MariaDB database of the account, with the users that may use it.
 *
 * @mixin Database
 */
class DatabaseResource extends JsonResource
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
            'size_bytes' => $this->size_bytes,
            'users' => $this->whenLoaded('users', fn () => $this->users->map(fn (DatabaseUser $user): string => $user->name)->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
