<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Notifications\Concerns\HasRetryPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * "A ticket is now yours." Sent to the new assignee and to nobody else.
 *
 * ShouldQueue is the whole of TM-52's fifth criterion: notify() pushes one
 * SendQueuedNotifications job and the request returns having touched no mail
 * transport. tries, backoff and timeout come from HasRetryPolicy -- TM-57.
 *
 * The body renders through the shared mail.layout view pair -- TM-56.
 */
class TicketAssignedNotification extends Notification implements ShouldQueue
{
    use HasRetryPolicy, Queueable, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly ?string $reason,
    ) {
        $this->applyRetryPolicy();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ticket = $this->ticket;

        return (new MailMessage)
            ->subject(sprintf('[%s] Assigned to you: %s', $ticket->reference, Str::limit($ticket->subject, 60)))
            ->view(
                ['mail.tickets.assigned', 'mail.tickets.assigned-text'],
                [
                    'agentName' => $notifiable->name,
                    'rows' => [
                        'Reference' => $ticket->reference,
                        'Subject' => $ticket->subject,
                        'Priority' => $ticket->priority->name,
                        'Category' => $ticket->category?->name,
                        'Requester' => sprintf('%s (%s)', $ticket->requester->name, $ticket->requester->email),
                    ],
                    'reason' => $this->reason,
                    'ticketUrl' => $this->ticketUrl($ticket),
                ],
            );
    }

    private function ticketUrl(Ticket $ticket): string
    {
        // The SPA route is /tickets/:id and loads with Number(route.params.id),
        // so this is the primary key — never the reference.
        return rtrim((string) config('app.frontend_url'), '/').'/tickets/'.$ticket->getKey();
    }

    /** @return array<string, scalar|null> */
    protected function failureContext(): array
    {
        return [
            'ticket_id' => $this->ticket->getKey(),
            'reference' => $this->ticket->reference,
            'recipient' => 'assignee',
        ];
    }
}
