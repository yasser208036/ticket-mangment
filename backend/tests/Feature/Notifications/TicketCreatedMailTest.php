<?php

namespace Tests\Feature\Notifications;

use App\Enums\TicketActivityEvent;
use App\Models\Category;
use App\Models\Priority;
use App\Models\Requester;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use Database\Seeders\CategorySeeder;
use Database\Seeders\PrioritySeeder;
use Database\Seeders\StatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Channels\MailChannel;
use Tests\TestCase;

class TicketCreatedMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CategorySeeder::class, PrioritySeeder::class, StatusSeeder::class]);
    }

    private function ticket(array $overrides = []): Ticket
    {
        $attributes = array_replace([
            'subject' => 'Printer offline',
            'description' => 'The office printer will not turn on.',
            'requester_id' => Requester::factory()->create()->getKey(),
            'category_id' => Category::query()->value('id'),
            'priority_id' => Priority::query()->where('is_default', true)->value('id'),
            'status_id' => Status::query()->where('is_default', true)->value('id'),
        ], $overrides);

        $ticket = new Ticket($attributes);
        $ticket->created_by = User::factory()->agent()->create()->getKey();
        $ticket->reference = 'TKT-2026-'.random_int(100000, 999999);
        $ticket->save();

        return $ticket;
    }

    /** @return array{0: string, 1: string} */
    private function renderBothParts(Ticket $ticket): array
    {
        $requester = $ticket->requester()->first() ?? Requester::factory()->create();
        app('mailer')->getSymfonyTransport()->flush();
        app(MailChannel::class)->send($requester, new TicketCreatedNotification($ticket->load('requester')));
        $message = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

        return [(string) $message->getHtmlBody(), (string) $message->getTextBody()];
    }

    public function test_it_leads_with_the_reference_and_echoes_the_subject_and_description(): void
    {
        $ticket = $this->ticket();
        [$html, $text] = $this->renderBothParts($ticket);

        foreach ([$html, $text] as $part) {
            $this->assertStringContainsString($ticket->reference, $part);
            $this->assertStringContainsString($ticket->subject, $part);
            $this->assertStringContainsString($ticket->description, $part);
        }

        $requester = $ticket->requester()->first();
        $mail = (new TicketCreatedNotification($ticket))->toMail($requester);
        $this->assertStringStartsWith("[{$ticket->reference}]", $mail->subject);
    }

    public function test_markdown_in_the_description_is_not_interpreted(): void
    {
        $description = "Steps:\n# 1 restart\n**check** the cable\n[link](http://evil.test)";
        $ticket = $this->ticket(['description' => $description]);
        [$html, $text] = $this->renderBothParts($ticket);

        foreach ([$html, $text] as $part) {
            $this->assertStringContainsString('**check**', $part);
            $this->assertStringContainsString('# 1 restart', $part);
            $this->assertStringContainsString('[link](http://evil.test)', $part);
        }

        $this->assertStringNotContainsString('<strong>check</strong>', $html);
        $this->assertStringNotContainsString('<h1>', $html);
        $this->assertStringNotContainsString('href="http://evil.test"', $html);
    }

    public function test_line_breaks_in_the_description_survive(): void
    {
        $ticket = $this->ticket(['description' => "line one\n\nline two"]);
        [$html, $text] = $this->renderBothParts($ticket);

        $this->assertStringContainsString("line one\n\nline two", $html);
        $this->assertStringContainsString("line one\n\nline two", $text);
    }

    public function test_it_promises_no_response_time(): void
    {
        $ticket = $this->ticket();
        [$html, $text] = $this->renderBothParts($ticket);

        $forbidden = ['sla', 'guarantee', 'within 24', 'within 48', 'business day', 'business hours', 'response time', 'as soon as possible'];
        foreach ([$html, $text] as $part) {
            $lower = mb_strtolower($part);
            foreach ($forbidden as $phrase) {
                $this->assertStringNotContainsString($phrase, $lower);
            }
            $this->assertStringContainsString('review your request', $lower);
        }
    }

    public function test_it_carries_no_agent_or_triage_information(): void
    {
        $agent = User::factory()->agent()->create(['name' => 'Secret Agent Name', 'email' => 'secret-agent@example.test']);
        $urgent = Priority::create(['name' => 'Urgent Priority Name', 'slug' => 'urgent-priority-name', 'level' => 99]);
        $ticket = $this->ticket(['priority_id' => $urgent->getKey(), 'assigned_to' => $agent->getKey()]);

        [$html, $text] = $this->renderBothParts($ticket);

        foreach ([$html, $text] as $part) {
            $this->assertStringNotContainsString('Secret Agent Name', $part);
            $this->assertStringNotContainsString('secret-agent@example.test', $part);
            $this->assertStringNotContainsString('Urgent Priority Name', $part);
        }
    }

    public function test_it_carries_no_activity_or_note_content(): void
    {
        $ticket = $this->ticket();
        TicketActivity::insert([
            'ticket_id' => $ticket->getKey(),
            'event' => TicketActivityEvent::Created->value,
            'created_at' => now(),
            'user_id' => null, 'field' => null, 'old_value' => null, 'new_value' => null,
            'meta' => json_encode(['note' => 'TM53-DISTINCTIVE-CANARY-STRING']),
        ]);

        [$html, $text] = $this->renderBothParts($ticket);

        $this->assertStringNotContainsString('TM53-DISTINCTIVE-CANARY-STRING', $html);
        $this->assertStringNotContainsString('TM53-DISTINCTIVE-CANARY-STRING', $text);
    }

    public function test_it_contains_no_link_to_the_application(): void
    {
        $ticket = $this->ticket();
        [$html, $text] = $this->renderBothParts($ticket);

        foreach ([$html, $text] as $part) {
            $this->assertStringNotContainsString('localhost:5173', $part);
            $this->assertStringNotContainsString((string) config('app.frontend_url'), $part);
            $this->assertStringNotContainsString('/tickets/', $part);
        }
    }

    public function test_html_in_the_description_is_escaped_in_the_html_part(): void
    {
        $ticket = $this->ticket(['description' => 'before <script>alert(1)</script> after']);
        [$html] = $this->renderBothParts($ticket);

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_the_text_part_is_not_html_escaped(): void
    {
        $ticket = $this->ticket(['description' => 'before <b>bold</b> after']);
        [, $text] = $this->renderBothParts($ticket);

        $this->assertStringContainsString('<b>bold</b>', $text);
        $this->assertStringNotContainsString('&lt;b&gt;', $text);
    }

    public function test_both_parts_are_present_and_non_empty(): void
    {
        [$html, $text] = $this->renderBothParts($this->ticket());

        $this->assertNotEmpty($html);
        $this->assertNotEmpty($text);
    }

    public function test_arabic_and_emoji_survive_both_parts(): void
    {
        $ticket = $this->ticket([
            'subject' => 'طلب دعم فني 🎫',
            'description' => "الطابعة لا تعمل 🖨️\nيرجى المساعدة",
        ]);

        [$html, $text] = $this->renderBothParts($ticket);

        foreach ([$html, $text] as $part) {
            $this->assertStringNotContainsString('�', $part);
            $this->assertStringContainsString('طلب دعم فني', $part);
        }
    }
}
