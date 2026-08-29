<?php

namespace Tests\Feature\Notifications;

use App\Models\Category;
use App\Models\Priority;
use App\Models\Requester;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketEscalatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Channels\MailChannel;
use Tests\TestCase;

class TicketEscalatedMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function ticket(array $overrides = []): Ticket
    {
        $attributes = array_replace([
            'subject' => 'Printer offline',
            'description' => 'The office printer will not turn on.',
            'requester_id' => Requester::factory()->create()->getKey(),
            'category_id' => Category::query()->value('id'),
            'priority_id' => Priority::query()->where('is_default', true)->value('id'),
            'status_id' => Status::where('slug', 'open')->sole()->getKey(),
        ], $overrides);

        $ticket = new Ticket($attributes);
        $ticket->created_by = User::factory()->agent()->create()->getKey();
        $ticket->reference = 'TKT-2026-'.random_int(100000, 999999);
        $ticket->save();

        return $ticket;
    }

    /** @return array{0: string, 1: string} */
    private function renderBothParts(Ticket $ticket, User $actor, int $level, string $reason): array
    {
        $admin = User::factory()->admin()->create();
        app('mailer')->getSymfonyTransport()->flush();
        app(MailChannel::class)->send($admin, new TicketEscalatedNotification($ticket->load(['requester', 'priority', 'assignee']), $actor, $level, $reason));
        $message = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

        return [(string) $message->getHtmlBody(), (string) $message->getTextBody()];
    }

    private function subjectFor(Ticket $ticket, User $actor, int $level, string $reason): string
    {
        $admin = User::factory()->admin()->create();

        return (new TicketEscalatedNotification($ticket->load(['requester', 'priority', 'assignee']), $actor, $level, $reason))->toMail($admin)->subject;
    }

    public function test_the_subject_starts_with_the_filter_token_at_level_one(): void
    {
        $ticket = $this->ticket();
        $actor = User::factory()->agent()->create();

        $subject = $this->subjectFor($ticket, $actor, 1, 'A reason at least ten chars.');

        $this->assertStringStartsWith('[ESCALATED] ', $subject);
        $this->assertStringContainsString($ticket->reference, $subject);
        $this->assertStringContainsString($ticket->subject, $subject);
    }

    public function test_the_subject_marks_a_repeat_escalation(): void
    {
        $ticket = $this->ticket();
        $actor = User::factory()->agent()->create();

        $subject = $this->subjectFor($ticket, $actor, 3, 'A reason at least ten chars.');

        $this->assertStringStartsWith('[ESCALATED ×3] ', $subject);
    }

    public function test_the_subject_never_renders_times_one(): void
    {
        $ticket = $this->ticket();
        $actor = User::factory()->agent()->create();

        $subject = $this->subjectFor($ticket, $actor, 1, 'A reason at least ten chars.');

        $this->assertStringNotContainsString('×', $subject);
    }

    public function test_the_filter_token_is_configurable(): void
    {
        config()->set('notifications.admin.escalation_subject_token', 'URGENT-ESC');
        $ticket = $this->ticket();
        $actor = User::factory()->agent()->create();

        $subject = $this->subjectFor($ticket, $actor, 1, 'A reason at least ten chars.');

        $this->assertStringStartsWith('[URGENT-ESC] ', $subject);
    }

    public function test_it_sets_the_escalation_headers(): void
    {
        $ticket = $this->ticket();
        $actor = User::factory()->agent()->create();
        $admin = User::factory()->admin()->create();
        app('mailer')->getSymfonyTransport()->flush();

        app(MailChannel::class)->send($admin, new TicketEscalatedNotification($ticket->load(['requester', 'priority', 'assignee']), $actor, 2, 'A reason at least ten chars.'));
        $message = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

        $this->assertSame('2', $message->getHeaders()->get('X-Ticket-Escalation-Level')->getBodyAsString());
        $this->assertSame($ticket->reference, $message->getHeaders()->get('X-Ticket-Reference')->getBodyAsString());
    }

    public function test_it_carries_the_reason_level_requester_and_escalator(): void
    {
        $ticket = $this->ticket();
        $actor = User::factory()->agent()->create(['name' => 'Escalating Agent']);

        [$html, $text] = $this->renderBothParts($ticket, $actor, 1, 'This needs urgent attention.');

        foreach ([$html, $text] as $part) {
            $this->assertStringContainsString('This needs urgent attention.', $part);
            $this->assertStringContainsString('Escalation level', $part);
            $requester = $ticket->requester()->first();
            $this->assertStringContainsString($requester->name, $part);
            $this->assertStringContainsString($requester->email, $part);
            $this->assertStringContainsString('Escalating Agent', $part);
        }
        // The summary partial renders "Label: value" only in the text part; the
        // HTML part uses two table cells with no colon between them.
        $this->assertStringContainsString('Escalation level: 1', $text);
    }

    public function test_a_repeat_escalation_says_so_in_the_body(): void
    {
        $ticket = $this->ticket();
        $actor = User::factory()->agent()->create();

        [$html2, $text2] = $this->renderBothParts($ticket, $actor, 2, 'A reason at least ten chars.');
        $this->assertStringContainsString('escalated 2 times', $html2);
        $this->assertStringContainsString('escalated 2 times', $text2);

        [$html1, $text1] = $this->renderBothParts($ticket, $actor, 1, 'A reason at least ten chars.');
        $this->assertStringNotContainsString('escalated 1 times', $html1);
        $this->assertStringNotContainsString('escalated 1 times', $text1);
    }

    public function test_markdown_and_line_breaks_in_the_reason_survive(): void
    {
        $reason = "Steps:\n# 1 restart\n**check** the cable\n[ref](http://evil.test)";
        $ticket = $this->ticket();
        $actor = User::factory()->agent()->create();

        [$html, $text] = $this->renderBothParts($ticket, $actor, 1, $reason);

        foreach ([$html, $text] as $part) {
            $this->assertStringContainsString('**check**', $part);
            $this->assertStringContainsString('# 1 restart', $part);
            $this->assertStringContainsString('[ref](http://evil.test)', $part);
        }
        $this->assertStringNotContainsString('<strong>check</strong>', $html);
        $this->assertStringNotContainsString('<h1>', $html);
        $this->assertStringNotContainsString('href="http://evil.test"', $html);
        $this->assertSame(1, substr_count($html, '<a href'));
    }

    public function test_html_in_the_reason_is_escaped_in_html_and_raw_in_text(): void
    {
        $ticket = $this->ticket();
        $actor = User::factory()->agent()->create();

        [$html, $text] = $this->renderBothParts($ticket, $actor, 1, 'before <script>alert(1)</script> and <b>bold</b> after');

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('<b>bold</b>', $text);
        $this->assertStringNotContainsString('&lt;b&gt;', $text);
    }

    public function test_it_links_to_the_spa_ticket_page_by_numeric_id(): void
    {
        config()->set('app.frontend_url', 'https://helpdesk.test');
        $ticket = $this->ticket();
        $actor = User::factory()->agent()->create();

        [$html, $text] = $this->renderBothParts($ticket, $actor, 1, 'A reason at least ten chars.');

        $expectedUrl = "https://helpdesk.test/tickets/{$ticket->getKey()}";
        $this->assertStringContainsString($expectedUrl, $html);
        $this->assertStringContainsString($expectedUrl, $text);
        $this->assertStringNotContainsString("/{$ticket->reference}", $html);
    }

    public function test_it_names_the_assignee_or_says_nobody(): void
    {
        $agent = User::factory()->agent()->create(['name' => 'Assigned Agent']);
        $ticket = $this->ticket(['assigned_to' => $agent->getKey()]);
        $actor = User::factory()->agent()->create();

        [$html] = $this->renderBothParts($ticket, $actor, 1, 'A reason at least ten chars.');
        $this->assertStringContainsString('Assigned Agent', $html);

        $unassigned = $this->ticket(['assigned_to' => null]);
        [$htmlUnassigned, $textUnassigned] = $this->renderBothParts($unassigned, $actor, 1, 'A reason at least ten chars.');
        $this->assertStringContainsString('nobody', $htmlUnassigned);
        $this->assertStringContainsString('Now assigned to: nobody', $textUnassigned);
    }

    public function test_both_parts_are_present_and_non_empty(): void
    {
        $ticket = $this->ticket();
        $actor = User::factory()->agent()->create();

        [$html, $text] = $this->renderBothParts($ticket, $actor, 1, 'A reason at least ten chars.');

        $this->assertNotEmpty($html);
        $this->assertNotEmpty($text);
    }

    public function test_arabic_and_emoji_survive_the_subject_and_the_reason(): void
    {
        $ticket = $this->ticket(['subject' => str_repeat('طلب دعم فني 🎫 ', 6)]);
        $actor = User::factory()->agent()->create();

        [$html, $text] = $this->renderBothParts($ticket, $actor, 1, "السبب: تعطل الخادم 🚨\nيرجى المساعدة فورا");

        foreach ([$html, $text] as $part) {
            $this->assertStringNotContainsString('�', $part);
        }
        $subject = $this->subjectFor($ticket, $actor, 1, 'A reason at least ten chars.');
        $this->assertStringNotContainsString('�', $subject);
    }
}
