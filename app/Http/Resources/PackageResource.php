<?php

namespace App\Http\Resources;

use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A hosting package; a null limit is unlimited.
 *
 * @mixin Package
 */
class PackageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'limits' => collect(Package::LIMITS)->mapWithKeys(fn (string $key): array => [$key => $this->limit($key)])->all(),
            'ssl' => $this->ssl,
            'cgi' => $this->cgi,
            'ssh' => $this->ssh,
        ];
    }
}
