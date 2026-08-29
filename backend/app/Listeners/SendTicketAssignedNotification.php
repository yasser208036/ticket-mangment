<?php

namespace App\Listeners;

use App\Events\TicketAssigned;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketAssignedNotification;
use Illuminate\Support\Facades\Log;

/**
 * Turns TM-34's domain event into TM-52's email.
 *
 * Deliberately NOT queued: the notification it sends is, so a single
 * assignment produces exactly one job rather than a job that queues a job.
 * The two reads below are primary-key lookups in a request whose transaction
 * has already committed.
 */
class SendTicketAssignedNotification
{
    public function handle(TicketAssigned $event): void
    {
        // AC3 — "No email is sent when a user assigns a ticket to themselves."
        // Enforced here, not at the dispatch sites: this is the one place every
        // producer passes through, and TM-34's plan names this story as where
        // the guard belongs if /assign ever accepts a self-target.
        if ($event->assigneeId === $event->actorId) {
            return;
        }

        $assignee = User::query()->whereKey($event->assigneeId)->active()->first();

        if ($assignee === null) {
            Log::info('Assignment notification skipped: assignee missing or inactive.', [
                'ticket_id' => $event->ticketId,
                'assignee_id' => $event->assigneeId,
            ]);

            return;
        }

        // whereKey() honours SoftDeletes, so a ticket deleted between the
        // commit and this line resolves to null and nothing is queued.
        // The category is loaded withTrashed() because Category soft-deletes
        // and the email should still name the category the ticket carries.
        $ticket = Ticket::query()
            ->whereKey($event->ticketId)
            ->with(['requester', 'priority', 'category' => fn ($query) => $query->withTrashed()])
            ->first();

        if ($ticket === null) {
            Log::info('Assignment notification skipped: ticket no longer exists.', [
                'ticket_id' => $event->ticketId,
                'assignee_id' => $event->assigneeId,
            ]);

            return;
        }

        $assignee->notify(new TicketAssignedNotification($ticket, $event->reason));
    }
}
