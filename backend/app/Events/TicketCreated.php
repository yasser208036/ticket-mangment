<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A ticket was logged. Dispatched once per successful creation, after the
 * transaction commits, so a confirmation can never quote a ticket that rolled
 * back — TM-57's fourth criterion, and the rule TM-34 set for TicketAssigned.
 *
 * The ticket id and nothing else: no actor, because nothing compares the
 * creator to anyone and TM-53's fifth criterion forbids naming them in the
 * email. Scalar id, no marker interfaces — the rule TM-34 set for this
 * directory. TM-53 attaches the queued confirmation.
 */
class TicketCreated
{
    use Dispatchable;

    public function __construct(
        public readonly int $ticketId,
    ) {}
}
