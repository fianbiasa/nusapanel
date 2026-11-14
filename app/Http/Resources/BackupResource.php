<?php

namespace App\Http\Resources;

use App\Models\ServerBackup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A backup the server keeps of a hosting account, as the API shows it.
 *
 * @mixin ServerBackup
 */
class BackupResource extends JsonResource
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
            'status' => $this->status->value,
            'automatic' => ! $this->isManual(),
            'files' => $this->files,
            'email' => $this->email,
            'databases' => $this->databases,
            'size' => $this->size_bytes,
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
