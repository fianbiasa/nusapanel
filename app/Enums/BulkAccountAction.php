<?php

namespace App\Enums;

/**
 * What an administrator can do to many accounts at once from the account
 * list.
 */
enum BulkAccountAction: string
{
    case Suspend = 'suspend';

    case Unsuspend = 'unsuspend';

    case Delete = 'delete';

    /** Move to another package, or take off packages. */
    case Package = 'package';
}
