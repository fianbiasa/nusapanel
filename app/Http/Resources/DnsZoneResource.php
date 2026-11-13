<?php

namespace App\Http\Resources;

use App\Models\DnsZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A DNS zone of the account, with its records.
 *
 * @mixin DnsZone
 */
class DnsZoneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'records' => DnsRecordResource::collection($this->whenLoaded('records')),
        ];
    }
}
