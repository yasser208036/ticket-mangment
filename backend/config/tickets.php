<?php

return [
    // A ticket with no change for this many hours is flagged stale by
    // `php artisan tickets:flag-stale`. "No change" means tickets.updated_at:
    // an internal note deliberately does not reset it (TM-47).
    'stale_after_hours' => (int) env('TICKETS_STALE_AFTER_HOURS', 48),

    // Whether a run dispatches TicketsFlaggedStale. Off by default because no
    // listener exists yet -- no story owns a stale-ticket digest.
    'stale_notify' => (bool) env('TICKETS_STALE_NOTIFY', false),
];
