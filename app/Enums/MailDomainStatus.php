<?php

namespace App\Enums;

/**
 * Where a mail domain stands. It takes mail once the agent has prepared its
 * folder and DKIM key (active).
 */
enum MailDomainStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';
}
