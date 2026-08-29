<?php

namespace Tests\Feature\Notifications;

use App\Models\Category;
use App\Models\Priority;
use App\Models\Requester;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketStatusChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Channels\MailChannel;
use Tests\TestCase;

class TicketStatusChangedMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function ticket(array $overrides = []): Ticket
    {
        $escalationReason = $overrides['escalation_reason'] ?? null;
        unset($overrides['escalation_reason']);

        $attributes = array_replace([
            'subject' => 'Printer offline',
            'description' => 'The office printer will not turn on.',
            'requester_id' => Requester::factory()->create()->getKey(),
            'category_id' => Category::query()->value('id'),
            'priority_id' => Priority::query()->where('is_default', true)->value('id'),
            'status_id' => Status::where('slug', 'in-progress')->sole()->getKey(),
        ], $overrides);

        $ticket = new Ticket($attributes);
        $ticket->created_by = User::factory()->agent()->create()->getKey();
        $ticket->reference = 'TKT-2026-'.random_int(100000, 999999);
        $ticket->escalation_reason = $escalationReason;
        $ticket->save();

        return $ticket;
    }

    private function statusNamed(string $slug): Status
    {
        return Status::where('slug', $slug)->sole();
    }

    /** @return array{0: string, 1: string} */
    private function renderBothParts(Ticket $ticket, Status $from, Status $to, ?string $resolution = null): array
    {
        $requester = $ticket->requester()->first();
        app('mailer')->getSymfonyTransport()->flush();
        app(MailChannel::class)->send($requester, new TicketStatusChangedNotification($ticket, $from, $to, $resolution));
        $message = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

        return [(string) $message->getHtmlBody(), (string) $message->getTextBody()];
    }

    public function test_it_names_the_old_and_new_status(): void
    {
        $ticket = $this->ticket();
        $from = $this->statusNamed('open');
        $to = $this->statusNamed('in-progress');

        [$html, $text] = $this->renderBothParts($ticket, $from, $to);

        foreach ([$html, $text] as $part) {
            $this->assertStringContainsString('Open', $part);
            $this->assertStringContainsString('In Progress', $part);
        }

        $requester = $ticket->requester()->first();
        $mail = (new TicketStatusChangedNotification($ticket, $from, $to, null))->toMail($requester);
        $this->assertSame("[{$ticket->reference}] Status update: {$to->name}", $mail->subject);
    }

    public function test_a_resolution_email_quotes_the_note(): void
    {
        $ticket = $this->ticket();
        [$html, $text] = $this->renderBothParts($ticket, $this->statusNamed('in-progress'), $this->statusNamed('resolved'), 'Replaced the network cable.');

        $this->assertStringContainsString('Replaced the network cable.', $html);
        $this->assertStringContainsString('Replaced the network cable.', $text);
    }

    public function test_markdown_and_line_breaks_in_the_resolution_survive(): void
    {
        $resolution = "Steps:\n# 1 replaced cable\n**checked** link\n[ref](http://evil.test)";
        $ticket = $this->ticket();

        [$html, $text] = $this->renderBothParts($ticket, $this->statusNamed('in-progress'), $this->statusNamed('resolved'), $resolution);

        foreach ([$html, $text] as $part) {
            $this->assertStringContainsString('**checked**', $part);
            $this->assertStringContainsString('# 1 replaced cable', $part);
            $this->assertStringContainsString('[ref](http://evil.test)', $part);
        }
        $this->assertStringNotContainsString('<strong>checked</strong>', $html);
        $this->assertStringNotContainsString('<h1>', $html);
        $this->assertStringNotContainsString('href="http://evil.test"', $html);
    }

    public function test_a_non_resolution_email_has_no_resolution_block(): void
    {
        $ticket = $this->ticket();
        [$html, $text] = $this->renderBothParts($ticket, $this->statusNamed('open'), $this->statusNamed('in-progress'));

        $this->assertStringNotContainsString('How it was resolved', $html);
        $this->assertStringNotContainsString('How it was resolved', $text);
    }

    public function test_a_resolution_of_the_string_zero_is_still_shown(): void
    {
        $ticket = $this->ticket();
        [$html, $text] = $this->renderBothParts($ticket, $this->statusNamed('in-progress'), $this->statusNamed('resolved'), '0');

        $this->assertStringContainsString('How it was resolved', $html);
        $this->assertStringContainsString('How it was resolved', $text);
    }

    public function test_a_reopen_email_does_not_quote_the_reopen_reason(): void
    {
        $ticket = $this->ticket();
        // The event never carries the reopen reason, so the notification is
        // constructed here exactly as the listener would: no fifth argument.
        [$html, $text] = $this->renderBothParts($ticket, $this->statusNamed('closed'), $this->statusNamed('reopened'));

        foreach ([$html, $text] as $part) {
            $this->assertStringNotContainsString('DISTINCTIVE-REOPEN-REASON', $part);
            $this->assertStringContainsString('Closed', $part);
            $this->assertStringContainsString('Reopened', $part);
        }
    }

    public function test_html_in_the_resolution_is_escaped_in_html_and_raw_in_text(): void
    {
        $ticket = $this->ticket();
        [$html, $text] = $this->renderBothParts(
            $ticket,
            $this->statusNamed('in-progress'),
            $this->statusNamed('resolved'),
            'before <script>alert(1)</script> and <b>bold</b> after',
        );

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('<b>bold</b>', $text);
        $this->assertStringNotContainsString('&lt;b&gt;', $text);
    }

    public function test_it_carries_no_agent_or_triage_information(): void
    {
        $agent = User::factory()->agent()->create(['name' => 'Secret Agent Name', 'email' => 'secret-agent@example.test']);
        $urgent = Priority::create(['name' => 'Urgent Priority Name', 'slug' => 'urgent-priority-name-2', 'level' => 98]);
        $ticket = $this->ticket(['priority_id' => $urgent->getKey(), 'assigned_to' => $agent->getKey(), 'escalation_reason' => 'DISTINCTIVE-ESCALATION-REASON']);

        [$html, $text] = $this->renderBothParts($ticket, $this->statusNamed('open'), $this->statusNamed('in-progress'));

        foreach ([$html, $text] as $part) {
            $this->assertStringNotContainsString('Secret Agent Name', $part);
            $this->assertStringNotContainsString('secret-agent@example.test', $part);
            $this->assertStringNotContainsString('Urgent Priority Name', $part);
            $this->assertStringNotContainsString('DISTINCTIVE-ESCALATION-REASON', $part);
        }
    }

    public function test_it_contains_no_link_to_the_application(): void
    {
        $ticket = $this->ticket();
        [$html, $text] = $this->renderBothParts($ticket, $this->statusNamed('open'), $this->statusNamed('in-progress'));

        foreach ([$html, $text] as $part) {
            $this->assertStringNotContainsString('localhost:5173', $part);
            $this->assertStringNotContainsString((string) config('app.frontend_url'), $part);
            $this->assertStringNotContainsString('/tickets/', $part);
        }
    }

    public function test_it_promises_no_response_time(): void
    {
        $ticket = $this->ticket();
        [$html, $text] = $this->renderBothParts($ticket, $this->statusNamed('open'), $this->statusNamed('in-progress'));

        $forbidden = ['sla', 'guarantee', 'within 24', 'within 48', 'business day', 'business hours', 'response time', 'as soon as possible'];
        foreach ([$html, $text] as $part) {
            $lower = mb_strtolower($part);
            foreach ($forbidden as $phrase) {
                $this->assertStringNotContainsString($phrase, $lower);
            }
        }
    }

    public function test_both_parts_are_present_and_non_empty(): void
    {
        $ticket = $this->ticket();
        [$html, $text] = $this->renderBothParts($ticket, $this->statusNamed('open'), $this->statusNamed('in-progress'));

        $this->assertNotEmpty($html);
        $this->assertNotEmpty($text);
    }

    public function test_arabic_and_emoji_survive_both_parts(): void
    {
        $ticket = $this->ticket(['subject' => 'طلب دعم فني 🎫']);
        [$html, $text] = $this->renderBothParts(
            $ticket,
            $this->statusNamed('in-progress'),
            $this->statusNamed('resolved'),
            "تم الحل 🎉\nتم استبدال الكابل",
        );

        foreach ([$html, $text] as $part) {
            $this->assertStringNotContainsString('�', $part);
            $this->assertStringContainsString('طلب دعم فني', $part);
            $this->assertStringContainsString('تم الحل', $part);
        }
    }
}
