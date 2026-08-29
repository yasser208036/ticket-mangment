<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * One event per run, carrying scalar ids. No ShouldQueue, no ShouldBroadcast,
 * no SerializesModels -- adding any of them changes the event's behaviour the
 * moment a queue worker starts.
 *
 * There is no listener yet. No story owns a stale-ticket digest; until one
 * exists, TICKETS_STALE_NOTIFY stays false.
 */
class TicketsFlaggedStale
{
    use Dispatchable;

    /** @param list<int> $ticketIds */
    public function __construct(
        public readonly array $ticketIds,
        public readonly int $thresholdHours,
    ) {}
}
