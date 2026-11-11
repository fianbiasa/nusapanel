<?php

namespace App\Enums;

/**
 * Whether a mailbox may sign in. A suspended mailbox keeps receiving mail
 * but may not sign in (IMAP, POP3, SMTP, webmail), so it sends nothing.
 */
enum MailboxStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
