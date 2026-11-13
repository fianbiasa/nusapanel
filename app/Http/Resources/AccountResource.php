<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A hosting account, as the API shows it to its administrator or reseller.
 *
 * @mixin User
 */
class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status->value,
            'suspend_reason' => $this->suspend_reason,
            'package' => $this->package === null ? null : ['id' => $this->package->id, 'name' => $this->package->name],
            'primary_domain' => $this->primaryDomain?->name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
