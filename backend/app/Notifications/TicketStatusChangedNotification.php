<?php

namespace App\Notifications;

use App\Models\Status;
use App\Models\Ticket;
use App\Notifications\Concerns\HasRetryPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * "Your ticket moved." Sent to the requester and nobody else.
 *
 * AC5 lives in two methods here. withDelay() holds the message back for the
 * configured window; shouldSend() drops it if the ticket has moved on since.
 * Together, an agent working a ticket through four statuses in one sitting
 * produces one email describing the final move rather than four near-identical
 * ones. Verified: SendQueuedNotifications::handle() reaches
 * NotificationSender::shouldSendNotification(), so shouldSend() runs in the
 * worker after the delay -- which is the only moment the check is meaningful.
 *
 * tries, backoff and timeout come from HasRetryPolicy -- TM-57.
 * The body renders through the shared mail.layout view pair -- TM-56.
 */
class TicketStatusChangedNotification extends Notification implements ShouldQueue
{
    use HasRetryPolicy, Queueable, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly Status $from,
        public readonly Status $to,
        public readonly ?string $resolution,
    ) {
        $this->applyRetryPolicy();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * NotificationSender::queueNotification() prefers this method over a $delay
     * property or a Delay attribute, so the window is read from config at queue
     * time rather than frozen when the object was constructed.
     */
    public function withDelay(object $notifiable, string $channel): int
    {
        return max(0, (int) config('notifications.requester.delay_seconds'));
    }

    /**
     * AC5. Runs in the worker, after the delay. False means "the ticket has
     * moved on, so this message is about a status it has already left".
     * Keyed on status_id, not on a timestamp: a ticket that went
     * in-progress -> pending -> in-progress inside the window should send one
     * email about the final in-progress and drop the middle one.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $current = Ticket::query()->whereKey($this->ticket->getKey())->value('status_id');

        return $current !== null && (int) $current === $this->to->getKey();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("[{$this->ticket->reference}] Status update: {$this->to->name}")
            ->view(
                ['mail.tickets.status-changed', 'mail.tickets.status-changed-text'],
                [
                    'requesterName' => $notifiable->name,
                    'rows' => [
                        'Reference' => $this->ticket->reference,
                        'Subject' => $this->ticket->subject,
                        'Status' => "{$this->from->name} \u{2192} {$this->to->name}",
                    ],
                    'resolution' => $this->resolution,
                ],
            );
    }

    /** @return array<string, scalar|null> */
    protected function failureContext(): array
    {
        return [
            'ticket_id' => $this->ticket->getKey(),
            'reference' => $this->ticket->reference,
            'to_status' => $this->to->slug,
            'recipient' => 'requester',
        ];
    }
}
