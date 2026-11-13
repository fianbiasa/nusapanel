<?php

namespace App\Http\Resources;

use App\Models\Mailbox;
use App\Services\MailService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An email account of the account. Its password is never shown.
 *
 * @mixin Mailbox
 */
class MailboxResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'address' => app(MailService::class)->address($this->resource),
            'domain' => $this->mailDomain->domain->name,
            'status' => $this->status->value,
            'quota_mb' => $this->quota_mb,
            'used_kb' => $this->used_kb,
            'message_count' => $this->message_count,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
