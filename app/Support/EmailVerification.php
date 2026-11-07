<?php

namespace App\Support;

/**
 * Email verification is only enforced when the panel can actually deliver
 * mail. With the "log" mailer (the default until SMTP or the server's own
 * mail service is set up) a verification link never arrives, so requiring it
 * would lock anyone who changes their email address out of the panel.
 *
 * Accounts are still marked unverified when their email changes, so once
 * mail is delivered they are asked to verify.
 */
final class EmailVerification
{
    /**
     * Mailers that never reach a real inbox.
     */
    private const UNDELIVERABLE_MAILERS = ['log'];

    public static function isRequired(): bool
    {
        return ! in_array(config('mail.default'), self::UNDELIVERABLE_MAILERS, true);
    }
}
