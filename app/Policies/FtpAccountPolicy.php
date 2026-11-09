<?php

namespace App\Policies;

use App\Models\FtpAccount;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class FtpAccountPolicy
{
    /**
     * Accounts may only change their own FTP logins. Other accounts' logins
     * are reported as missing so their existence is not revealed.
     */
    public function update(User $user, FtpAccount $ftpAccount): Response
    {
        return $this->own($user, $ftpAccount);
    }

    /**
     * Accounts may only delete their own FTP logins.
     */
    public function delete(User $user, FtpAccount $ftpAccount): Response
    {
        return $this->own($user, $ftpAccount);
    }

    private function own(User $user, FtpAccount $ftpAccount): Response
    {
        return $ftpAccount->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
