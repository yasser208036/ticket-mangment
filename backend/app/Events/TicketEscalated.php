<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A ticket was escalated. Dispatched once per successful escalation, after the
 * transaction commits -- TM-57's fourth criterion, and the rule TM-34 set for
 * TicketAssigned.
 *
 * The level and the reason ride along rather than being read off the ticket:
 * a second escalation seconds later would otherwise make the first email
 * report the second one's level and quote the second one's reason. This is the
 * same argument TM-34 made for `reason` and TM-54 for the status ids.
 *
 * Scalar values, no models, no marker interfaces -- the rule TM-34 set for
 * this directory.
 */
class TicketEscalated
{
    use Dispatchable;

    public function __construct(
        public readonly int $ticketId,
        public readonly int $actorId,
        public readonly int $level,
        public readonly string $reason,
    ) {}
}
