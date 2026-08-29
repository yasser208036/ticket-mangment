<?php

namespace App\Services;

use App\Models\Status;
use App\Models\Ticket;

class TicketTimestamps
{
    public function apply(Ticket $ticket, Status $from, Status $to): void
    {
        if ($from->slug === Status::SLUG_NEW && $to->slug !== Status::SLUG_NEW && $ticket->first_responded_at === null) {
            $ticket->first_responded_at = now();
        }
        if (! $to->is_terminal) {
            $ticket->resolved_at = null;
            $ticket->closed_at = null;

            return;
        }
        if ($to->slug === Status::SLUG_RESOLVED) {
            $ticket->resolved_at = now();
        }
        if ($to->slug === Status::SLUG_CLOSED) {
            $ticket->closed_at = now();
        }
    }
}
