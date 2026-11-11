<?php

namespace App\Enums;

/**
 * Where a domain's certificate comes from.
 */
enum SslSource: string
{
    /** Issued and renewed automatically by Let's Encrypt. */
    case LetsEncrypt = 'letsencrypt';

    /** Brought by the customer, e.g. bought from a CA or a Cloudflare Origin Certificate; never renewed by the panel. */
    case Custom = 'custom';

    /** The wildcard Let's Encrypt certificate of the subdomain's domain (*.<domain>), renewed with it. */
    case Parent = 'parent';
}
