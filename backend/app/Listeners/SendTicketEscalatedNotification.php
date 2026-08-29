<?php

namespace App\Listeners;

use App\Enums\UserRole;
use App\Events\TicketEscalated;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketEscalatedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Turns an escalation into one email per active admin.
 *
 * Not queued: the notification it sends is, and Notification::send() fans out
 * to one SendQueuedNotifications job per notifiable -- verified in
 * NotificationSender::queueNotification(), which loops the notifiables and
 * dispatches inside the loop. Three active admins therefore produce three jobs,
 * which is what the tests count.
 */
class SendTicketEscalatedNotification
{
    public function handle(TicketEscalated $event): void
    {
        $ticket = Ticket::query()
            ->whereKey($event->ticketId)
            ->with(['requester', 'priority', 'assignee'])
            ->first();

        $actor = User::query()->find($event->actorId);

        if ($ticket === null || $actor === null) {
            Log::warning('Escalation notification skipped: ticket or actor no longer exists.', [
                'ticket_id' => $event->ticketId,
                'actor_id' => $event->actorId,
            ]);

            return;
        }

        $admins = User::query()
            ->where('role', UserRole::Admin)
            ->active()
            ->get();

        if (! config('notifications.admin.notify_escalating_admin', true)) {
            $admins = $admins->reject(fn (User $admin): bool => $admin->is($actor));
        }

        if ($admins->isEmpty()) {
            // Story 35 refuses an escalation with a 422 when no active admin
            // exists, so this means every admin was deactivated between the
            // commit and this line. Nothing to send, and worth knowing about.
            Log::warning('Escalation notification skipped: no active admin to notify.', [
                'ticket_id' => $ticket->getKey(),
                'reference' => $ticket->reference,
            ]);

            return;
        }

        Notification::send(
            $admins,
            new TicketEscalatedNotification($ticket, $actor, $event->level, $event->reason),
        );
    }
}
