<?php

namespace App\Services;

use App\Data\AssignmentChange;
use App\Enums\TicketActivityEvent;
use App\Models\Ticket;
use App\Models\User;

/**
 * The single writer of tickets.assigned_to. Both TicketController::assign()
 * and Admin\AssignmentRequestController::approve() call changeAssignee() so
 * assignment history is written in exactly one shape, regardless of entry
 * point -- see the plan's "single assignment writer" decision.
 */
class TicketAssignment
{
    public function changeAssignee(Ticket $ticket, ActivityRecorder $recorder, AssignmentChange $change): bool
    {
        Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
        $ticket->refresh();
        $previousId = $ticket->assigned_to;
        $ticket->assigned_to = $change->targetId;
        if ($ticket->getDirty() === []) {
            return false;
        }
        $ticket->save();
        $this->recordAssignment($ticket, $recorder, $previousId, $change);

        return true;
    }

    private function recordAssignment(Ticket $ticket, ActivityRecorder $recorder, ?int $previousId, AssignmentChange $change): void
    {
        $recorder->record($ticket->getKey(), $change->targetId === null ? TicketActivityEvent::Unassigned : TicketActivityEvent::Assigned, [
            'user_id' => $change->actorId, 'field' => 'assigned_to',
            'old_value' => $previousId === null ? null : (string) $previousId,
            'new_value' => $change->targetId === null ? null : (string) $change->targetId,
            'meta' => [...($change->reason === null ? [] : ['reason' => $change->reason]), 'from_name' => $this->userName($previousId), 'to_name' => $this->userName($change->targetId)],
        ]);
    }

    private function userName(?int $userId): ?string
    {
        return $userId === null ? null : User::query()->whereKey($userId)->value('name');
    }
}
