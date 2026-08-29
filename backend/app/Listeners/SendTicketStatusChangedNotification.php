<?php

namespace App\Listeners;

use App\Events\TicketStatusChanged;
use App\Models\Status;
use App\Models\Ticket;
use App\Notifications\TicketStatusChangedNotification;
use Illuminate\Support\Facades\Log;

/**
 * Turns a transition into the requester's update.
 *
 * Not queued: the notification it sends is, so one transition produces exactly
 * one job. AC4 is enforced here -- an internal-only target queues nothing at
 * all, rather than queueing a job that later decides not to send.
 */
class SendTicketStatusChangedNotification
{
    public function handle(TicketStatusChanged $event): void
    {
        $to = Status::query()->find($event->toStatusId);

        // AC4, and AC3's call site: the list is configuration, never a match arm.
        if ($to === null || ! in_array($to->slug, (array) config('notifications.requester.visible_statuses', []), true)) {
            return;
        }

        $from = Status::query()->find($event->fromStatusId);

        $ticket = Ticket::query()
            ->whereKey($event->ticketId)
            ->with('requester')
            ->first();

        if ($ticket === null || $from === null) {
            Log::warning('Status notification skipped: ticket or origin status no longer exists.', [
                'ticket_id' => $event->ticketId,
            ]);

            return;
        }

        // requesters.email is NOT NULL and StoreTicketRequest requires a valid
        // address, so the API cannot reach this -- but NOT NULL does not forbid
        // '', which an import can write. Same guard as TM-53's listener.
        if (blank($ticket->requester->email)) {
            Log::warning('Status notification skipped: requester has no email address.', [
                'ticket_id' => $ticket->getKey(),
                'reference' => $ticket->reference,
            ]);

            return;
        }

        $ticket->requester->notify(
            new TicketStatusChangedNotification($ticket, $from, $to, $event->resolution),
        );
    }
}
