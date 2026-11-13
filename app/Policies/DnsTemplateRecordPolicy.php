<?php

namespace App\Policies;

use App\Models\DnsTemplateRecord;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Administrators change the administrators' template; a reseller only its
 * own. Records of other templates are reported as missing.
 */
class DnsTemplateRecordPolicy
{
    public function manage(User $actor, DnsTemplateRecord $record): Response
    {
        $allowed = $actor->isReseller()
            ? $record->owner_id === $actor->id
            : $actor->isAdmin() && $record->owner_id === null;

        return $allowed ? Response::allow() : Response::denyAsNotFound();
    }
}
