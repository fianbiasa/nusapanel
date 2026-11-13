<?php

namespace App\Enums;

/**
 * Where a ticket stands: waiting for an answer (open), answered and
 * waiting for its sender (answered), or done (closed).
 */
enum TicketStatus: string
{
    case Open = 'open';
    case Answered = 'answered';
    case Closed = 'closed';
}
