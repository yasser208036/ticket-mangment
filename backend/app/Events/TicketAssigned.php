<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class TicketAssigned
{
    use Dispatchable;

    public function __construct(
        public readonly int $ticketId,
        public readonly int $assigneeId,
        public readonly int $actorId,
        public readonly ?string $reason,
    ) {}
}
