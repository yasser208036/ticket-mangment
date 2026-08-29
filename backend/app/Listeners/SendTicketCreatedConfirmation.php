<?php

namespace App\Listeners;

use App\Events\TicketCreated;
use App\Models\Ticket;
use App\Notifications\TicketCreatedNotification;
use Illuminate\Support\Facades\Log;

/**
 * Turns TicketCreated into the requester's confirmation.
 *
 * Not queued: the notification it sends is, so one creation produces exactly
 * one job rather than a job that queues a job. The single read below is a
 * primary-key lookup in a request whose transaction has already committed.
 */
class SendTicketCreatedConfirmation
{
    public function handle(TicketCreated $event): void
    {
        // whereKey() honours SoftDeletes, so a ticket deleted between the
        // commit and this line resolves to null and nothing is queued.
        $ticket = Ticket::query()
            ->whereKey($event->ticketId)
            ->with('requester')
            ->first();

        if ($ticket === null) {
            Log::warning('Ticket confirmation skipped: ticket no longer exists.', [
                'ticket_id' => $event->ticketId,
            ]);

            return;
        }

        // AC4 — "A ticket created without a requester email queues nothing and
        // logs the skip." requesters.email is NOT NULL and StoreTicketRequest
        // requires a valid address, so the API cannot reach this. NOT NULL does
        // not forbid '', which an import or a seeder can write, so blank()
        // rather than is_null().
        if (blank($ticket->requester->email)) {
            Log::warning('Ticket confirmation skipped: requester has no email address.', [
                'ticket_id' => $ticket->getKey(),
                'reference' => $ticket->reference,
                'requester_id' => $ticket->requester->getKey(),
            ]);

            return;
        }

        $ticket->requester->notify(new TicketCreatedNotification($ticket));
    }
}
