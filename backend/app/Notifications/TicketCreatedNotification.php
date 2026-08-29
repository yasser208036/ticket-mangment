<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Notifications\Concerns\HasRetryPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * "We have logged your request." Sent to the requester's address and nowhere
 * else. Carries the reference, the subject and the description as submitted,
 * and deliberately nothing about priority, status, category, assignment or
 * history — TM-53's fifth criterion, and TM-47's deferred one.
 *
 * A view pair rather than MailMessage's line-based markdown builder: the markdown pipeline collapses
 * the description's newlines and turns a requester's own [text](url) into a
 * live link, which AC2's "as submitted" forbids. Measured while planning.
 *
 * tries, backoff and timeout come from HasRetryPolicy -- TM-57.
 * The body renders through the shared mail.layout view pair -- TM-56.
 */
class TicketCreatedNotification extends Notification implements ShouldQueue
{
    use HasRetryPolicy, Queueable, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
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
            ->subject("[{$ticket->reference}] We have logged your request")
            // The two-element list form, measured: MailChannel::buildView()
            // returns this verbatim, so no markdown renderer is involved.
            ->view(
                ['mail.tickets.created', 'mail.tickets.created-text'],
                [
                    'requesterName' => $notifiable->name,
                    'rows' => [
                        'Reference' => $ticket->reference,
                        'Subject' => $ticket->subject,
                    ],
                    'description' => $ticket->description,
                ],
            );
    }

    /** @return array<string, scalar|null> */
    protected function failureContext(): array
    {
        return [
            'ticket_id' => $this->ticket->getKey(),
            'reference' => $this->ticket->reference,
            'recipient' => 'requester',
        ];
    }
}
