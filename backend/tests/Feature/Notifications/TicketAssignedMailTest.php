<?php

namespace Tests\Feature\Notifications;

use App\Events\TicketAssigned;
use App\Listeners\SendTicketAssignedNotification;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketAssignedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TicketAssignedMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_it_contains_the_reference_subject_priority_category_and_requester(): void
    {
        $ticket = Ticket::factory()->create()->load(['priority', 'category', 'requester']);
        $assignee = User::factory()->agent()->create();

        $mail = (new TicketAssignedNotification($ticket, null))->toMail($assignee);
        $body = $mail->render();

        $this->assertStringContainsString($ticket->reference, $body);
        $this->assertStringContainsString($ticket->subject, $body);
        $this->assertStringContainsString($ticket->priority->name, $body);
        $this->assertStringContainsString($ticket->category->name, $body);
        $this->assertStringContainsString($ticket->requester->name, $body);
        $this->assertStringContainsString($ticket->requester->email, $body);
    }

    public function test_the_subject_line_leads_with_the_reference(): void
    {
        $ticket = Ticket::factory()->create();
        $assignee = User::factory()->agent()->create();

        $mail = (new TicketAssignedNotification($ticket, null))->toMail($assignee);

        $this->assertStringStartsWith("[{$ticket->reference}]", $mail->subject);
    }

    /**
     * TM-56 moved this notification off MailMessage->line()/->action() onto a
     * view pair, which sets $this->markdown = null and makes $mail->actionUrl
     * null. The link now lives only in the rendered output, via the shared
     * mail.partials.link partial -- so this asserts the rendered parts instead.
     */
    public function test_it_links_to_the_spa_ticket_page_by_numeric_id(): void
    {
        config()->set('app.frontend_url', 'https://helpdesk.test');
        $ticket = Ticket::factory()->create();
        $assignee = User::factory()->agent()->create();
        app('mailer')->getSymfonyTransport()->flush();

        app(MailChannel::class)->send($assignee, new TicketAssignedNotification($ticket, null));
        $message = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

        $expectedUrl = "https://helpdesk.test/tickets/{$ticket->getKey()}";
        $this->assertStringContainsString($expectedUrl, (string) $message->getHtmlBody());
        $this->assertStringContainsString($expectedUrl, (string) $message->getTextBody());
        $this->assertStringNotContainsString("/{$ticket->reference}", (string) $message->getHtmlBody());
    }

    public function test_a_trailing_slash_on_the_frontend_url_does_not_double(): void
    {
        config()->set('app.frontend_url', 'https://helpdesk.test/');
        $ticket = Ticket::factory()->create();
        $assignee = User::factory()->agent()->create();
        app('mailer')->getSymfonyTransport()->flush();

        app(MailChannel::class)->send($assignee, new TicketAssignedNotification($ticket, null));
        $message = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

        $expectedUrl = "https://helpdesk.test/tickets/{$ticket->getKey()}";
        $this->assertStringContainsString($expectedUrl, (string) $message->getHtmlBody());
        $this->assertStringNotContainsString('helpdesk.test//tickets', (string) $message->getHtmlBody());
    }

    public function test_the_handover_reason_appears_only_when_one_was_given(): void
    {
        $ticket = Ticket::factory()->create();
        $assignee = User::factory()->agent()->create();

        $withReason = (new TicketAssignedNotification($ticket, 'Please prioritise this'))->toMail($assignee);
        $renderedWithReason = $withReason->render();
        $this->assertStringContainsString('Handover note:', $renderedWithReason);
        $this->assertStringContainsString('Please prioritise this', $renderedWithReason);

        $withoutReason = (new TicketAssignedNotification($ticket, null))->toMail($assignee);
        $this->assertStringNotContainsString('Handover note', $withoutReason->render());
    }

    public function test_it_renders_a_plain_text_part_alongside_the_html(): void
    {
        $ticket = Ticket::factory()->create();
        $assignee = User::factory()->agent()->create();
        $notification = new TicketAssignedNotification($ticket, null);

        // Sent through the mail channel directly, bypassing ShouldQueue, so the
        // Symfony message can be inspected for both parts synchronously.
        // Rendered through the shared layout (TM-56), roughly 1.5 KB -- the
        // framework's default markdown template rendered this same email at
        // html_len=11676 before the move.
        app(MailChannel::class)->send($assignee, $notification);
        $sent = app('mailer')->getSymfonyTransport()->messages();

        $this->assertCount(1, $sent);
        $message = $sent[0]->getOriginalMessage();
        $this->assertNotEmpty((string) $message->getHtmlBody());
        $this->assertNotEmpty((string) $message->getTextBody());
    }

    public function test_it_names_a_soft_deleted_category(): void
    {
        Notification::fake();
        $ticket = Ticket::factory()->create()->load('category');
        $categoryName = $ticket->category->name;
        $ticket->category->delete();
        $assignee = User::factory()->agent()->create();
        $actor = User::factory()->admin()->create();

        (new SendTicketAssignedNotification)->handle(
            new TicketAssigned($ticket->getKey(), $assignee->getKey(), $actor->getKey(), null)
        );

        Notification::assertSentTo(
            $assignee,
            TicketAssignedNotification::class,
            fn (TicketAssignedNotification $notification) => str_contains($notification->toMail($assignee)->render(), $categoryName),
        );
    }

    public function test_it_does_not_leak_the_assigning_admins_email(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'admin-secret@ticket-management.test']);
        $ticket = Ticket::factory()->create();
        $assignee = User::factory()->agent()->create();

        $mail = (new TicketAssignedNotification($ticket, null))->toMail($assignee);
        $body = $mail->render();

        $this->assertStringNotContainsString($admin->email, $body);
    }

    public function test_a_long_subject_is_truncated_in_the_header_but_not_in_the_body(): void
    {
        $longSubject = trim(str_repeat('a very long subject line ', 10));
        $ticket = Ticket::factory()->create(['subject' => $longSubject]);
        $assignee = User::factory()->agent()->create();

        $mail = (new TicketAssignedNotification($ticket, null))->toMail($assignee);

        $this->assertLessThanOrEqual(120, mb_strlen($mail->subject));
        $this->assertStringContainsString($longSubject, $mail->render());
    }

    public function test_arabic_and_emoji_survive_the_subject_header(): void
    {
        $subject = str_repeat('طلب دعم فني 🎫 ', 6);
        $ticket = Ticket::factory()->create(['subject' => $subject]);
        $assignee = User::factory()->agent()->create();

        $mail = (new TicketAssignedNotification($ticket, null))->toMail($assignee);

        $this->assertStringNotContainsString('�', $mail->subject);
    }
}
