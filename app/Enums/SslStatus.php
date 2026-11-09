<?php

namespace App\Enums;

/**
 * Where a domain's Let's Encrypt certificate stands. A domain without a
 * certificate has no SSL status at all.
 */
enum SslStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';
    case Removing = 'removing';

    /**
     * A new certificate the customer brought is being installed; the
     * website keeps serving HTTPS with its current one meanwhile.
     */
    case Replacing = 'replacing';

    /**
     * The agent is still issuing or removing the certificate.
     */
    public function isBusy(): bool
    {
        return in_array($this, [self::Pending, self::Removing, self::Replacing], true);
    }
}
