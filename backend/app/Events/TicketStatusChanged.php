<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A ticket's status moved. Dispatched once per successful transition, after the
 * transaction commits -- TM-57's fourth criterion, and the rule TM-34 set for
 * TicketAssigned.
 *
 * Status ids rather than names: an admin can rename a status, and with TM-54's
 * delay there are minutes between dispatch and delivery. The listener resolves
 * both against `statuses`, seven rows behind a primary-key lookup.
 *
 * `resolution` rides along so the email can quote the note without re-reading
 * ticket_activities. The reopen `reason` deliberately does NOT: it is a note
 * staff write to each other, and TM-54 AC2 asks only for the resolution.
 *
 * Scalar ids, no models, no marker interfaces -- the rule TM-34 set for this
 * directory.
 */
class TicketStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int $ticketId,
        public readonly int $fromStatusId,
        public readonly int $toStatusId,
        public readonly ?string $resolution,
    ) {}
}
