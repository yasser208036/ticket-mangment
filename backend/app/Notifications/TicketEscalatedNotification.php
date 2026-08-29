<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\User;
use App\Notifications\Concerns\HasRetryPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

/**
 * "A ticket has been escalated." Sent to every active admin.
 *
 * Deliberately NOT delayed and NOT superseded, unlike TM-54's status email:
 * escalation is a deliberate act with a mandatory reason, level 1 -> level 2 is
 * exactly the distinction AC4 requires, and this is the "reach me when I am not
 * looking at the board" signal -- holding it back defeats the purpose.
 *
 * tries, backoff and timeout come from HasRetryPolicy -- TM-57.
 * The body renders through the shared mail.layout view pair -- TM-56.
 */
class TicketEscalatedNotification extends Notification implements ShouldQueue
{
    use HasRetryPolicy, Queueable, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly User $actor,
        public readonly int $level,
        public readonly string $reason,
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
            ->subject(sprintf(
                '[%s] [%s] %s',
                $this->subjectToken(),
                $ticket->reference,
                Str::limit($ticket->subject, 60),
            ))
            ->withSymfonyMessage(function (Email $message): void {
                // Numeric filtering, which the subject token cannot express.
                $message->getHeaders()->addTextHeader('X-Ticket-Escalation-Level', (string) $this->level);
                $message->getHeaders()->addTextHeader('X-Ticket-Reference', $this->ticket->reference);
            })
            ->view(
                ['mail.tickets.escalated', 'mail.tickets.escalated-text'],
                [
                    'adminName' => $notifiable->name,
                    'level' => $this->level,
                    'reason' => $this->reason,
                    'escalatedBy' => $this->actor->name,
                    'rows' => [
                        'Reference' => $ticket->reference,
                        'Subject' => $ticket->subject,
                        'Escalation level' => $this->level,
                        'Priority' => $ticket->priority->name,
                        'Requester' => sprintf('%s (%s)', $ticket->requester->name, $ticket->requester->email),
                        'Now assigned to' => $ticket->assignee?->name ?? 'nobody',
                    ],
                    'ticketUrl' => $this->ticketUrl($ticket),
                ],
            );
    }

    /**
     * AC3 and AC4. `[ESCALATED` is the invariant prefix every mail rule matches;
     * the suffix appears only above level one -- TM-42's badge rule, in a
     * different medium: a bare token IS the level-one signal, and there is never
     * an `×1`.
     */
    private function subjectToken(): string
    {
        $token = (string) config('notifications.admin.escalation_subject_token', 'ESCALATED');

        return $this->level > 1 ? "{$token} ×{$this->level}" : $token;
    }

    private function ticketUrl(Ticket $ticket): string
    {
        // The SPA route is /tickets/:id and loads with Number(route.params.id),
        // so this is the primary key -- never the reference. TM-52's decision.
        return rtrim((string) config('app.frontend_url'), '/').'/tickets/'.$ticket->getKey();
    }

    /** @return array<string, scalar|null> */
    protected function failureContext(): array
    {
        return [
            'ticket_id' => $this->ticket->getKey(),
            'reference' => $this->ticket->reference,
            'level' => $this->level,
            'recipient' => 'admins',
        ];
    }
}
