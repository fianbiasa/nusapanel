<?php

namespace App\Enums;

/**
 * What a website of an account is. Subdomains and pointers belong to one
 * of the account's domains, their parent.
 */
enum DomainType: string
{
    /** A domain with its own folder, domains/<name>/public_html. */
    case Domain = 'domain';

    /** A subdomain of a domain, with its own folder too. */
    case Subdomain = 'subdomain';

    /** Another domain that shows, or redirects to, its parent's website. */
    case Pointer = 'pointer';

    /**
     * The package limit the websites of this type count against.
     */
    public function limitKey(): string
    {
        return match ($this) {
            self::Domain => 'domains',
            self::Subdomain => 'subdomains',
            self::Pointer => 'pointers',
        };
    }
}
