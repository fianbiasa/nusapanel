<?php

namespace App\Http\Resources;

use App\Models\Domain;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A domain, subdomain or pointer of the account.
 *
 * @mixin Domain
 */
class DomainResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'type' => $this->type->value,
            'parent_id' => $this->parent_id,
            'status' => $this->status->value,
            'is_primary' => $this->is_primary,
            'php_version' => $this->php_version,
            'php_enabled' => $this->php_enabled,
            'ssl' => [
                'status' => $this->ssl_status?->value,
                'expires_at' => $this->ssl_expires_at?->toIso8601String(),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
