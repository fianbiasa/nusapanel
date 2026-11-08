<?php

namespace App\Enums;

/**
 * What a domain pointer does with its visitors.
 */
enum PointerMode: string
{
    /** Show the website of the parent domain under the pointer's own name. */
    case Alias = 'alias';

    /** Redirect every visitor to the parent domain (301). */
    case Redirect = 'redirect';

    /**
     * The kind of site the agent serves for a pointer in this mode.
     */
    public function agentKind(): string
    {
        return match ($this) {
            self::Alias => 'alias',
            self::Redirect => 'forward',
        };
    }
}
