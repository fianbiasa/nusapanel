<?php

namespace App\Enums;

/**
 * Who takes a mail domain's mail: this server (local), or another service
 * such as Google Workspace or Zoho (external), which Postfix then sends the
 * domain's mail to through its MX.
 */
enum MailDomainMode: string
{
    case Local = 'local';
    case External = 'external';
}
