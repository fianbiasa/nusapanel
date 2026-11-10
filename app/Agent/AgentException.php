<?php

namespace App\Agent;

use RuntimeException;
use Throwable;

class AgentException extends RuntimeException
{
    /**
     * @param  string|null  $reason  Why the agent refused, when it says so in a
     *                               form the panel can act on, e.g. "exists" for a
     *                               file that is already there.
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?string $reason = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
