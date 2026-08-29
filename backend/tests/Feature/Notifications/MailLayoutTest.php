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
use App\Notifications\TicketAssignedNotification;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketEscalatedNotification;
use App\Notifications\TicketStatusChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Cross-cutting suite for the shared mail layout (TM-56). Every assertion here
 * runs once against all four notifications, built from one deliberately
 * hostile fixture. Add a fifth notification to notifications() when one
 * exists -- one absent from this array is one nobody is checking.
 */
class MailLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** @return array<string, array{0: Notification, 1: object}> */
    private function notifications(): array
    {
        $requester = Requester::factory()->create(['name' => 'Réquesteur Müller']);
        $creator = User::factory()->agent()->create();
        $assignee = User::factory()->agent()->create(['email' => 'leak-me@staff.test']);
        $admin = User::factory()->admin()->create();
        $category = Category::create(['name' => 'Layout Category', 'slug' => 'layout-category-'.random_int(1000, 9999)]);
        $urgent = Priority::create(['name' => 'Layout Urgent', 'slug' => 'layout-urgent', 'level' => 97]);
        $openStatus = Status::where('slug', 'open')->sole();
        $inProgressStatus = Status::where('slug', 'in-progress')->sole();

        $ticket = new Ticket([
            'subject' => 'Hostile fixture ticket',
            'description' => 'Free text description for the created email.',
            'requester_id' => $requester->getKey(),
            'category_id' => $category->getKey(),
            'priority_id' => $urgent->getKey(),
            'status_id' => $inProgressStatus->getKey(),
            'assigned_to' => $assignee->getKey(),
        ]);
        $ticket->created_by = $creator->getKey();
        $ticket->reference = 'TKT-2026-'.random_int(100000, 999999);
        $ticket->escalation_reason = 'Escalation reason text';
        $ticket->save();

        TicketActivity::insert([
            'ticket_id' => $ticket->getKey(),
            'event' => TicketActivityEvent::Created->value,
            'created_at' => now(),
            'user_id' => null, 'field' => null, 'old_value' => null, 'new_value' => null,
            'meta' => json_encode(['note' => 'INTERNAL-NOTE-CANARY']),
        ]);

        $category->delete();
        $ticket->load(['requester', 'category', 'priority', 'assignee']);

        return [
            'assigned' => [new TicketAssignedNotification($ticket, 'Handover reason text'), $assignee],
            'created' => [new TicketCreatedNotification($ticket), $requester],
            'status' => [new TicketStatusChangedNotification($ticket, $openStatus, $inProgressStatus, 'Resolution note text'), $requester],
            'escalated' => [new TicketEscalatedNotification($ticket, $creator, 2, 'Escalation reason text'), $admin],
        ];
    }

    /** @return array{0: string, 1: string} */
    private function parts(Notification $notification, object $notifiable): array
    {
        app('mailer')->getSymfonyTransport()->flush();
        app(MailChannel::class)->send($notifiable, $notification);
        $message = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

        return [(string) $message->getHtmlBody(), (string) $message->getTextBody()];
    }

    public function test_every_notification_renders_a_non_empty_html_and_text_part(): void
    {
        foreach ($this->notifications() as $key => [$notification, $notifiable]) {
            [$html, $text] = $this->parts($notification, $notifiable);
            $this->assertNotEmpty($html, "{$key}: empty HTML part");
            $this->assertNotEmpty($text, "{$key}: empty text part");
        }
    }

    public function test_every_notification_uses_the_shared_layout(): void
    {
        foreach ($this->notifications() as $key => [$notification, $notifiable]) {
            [$html, $text] = $this->parts($notification, $notifiable);
            $this->assertStringContainsString('<!DOCTYPE', $html, $key);
            $this->assertStringContainsString('class="tm-card"', $html, $key);
            $this->assertStringContainsString('max-width:600px', $html, $key);
            $this->assertStringContainsString(config('app.name'), $html, $key);
            $this->assertStringContainsString(config('app.name'), $text, $key);
        }
    }

    public function test_every_notification_carries_the_mobile_rule(): void
    {
        foreach ($this->notifications() as $key => [$notification, $notifiable]) {
            [$html] = $this->parts($notification, $notifiable);
            $this->assertStringContainsString('@media only screen and (max-width: 600px)', $html, $key);
            $this->assertStringContainsString('width: 100% !important', $html, $key);
        }
    }

    public function test_every_notification_is_readable_with_css_stripped(): void
    {
        foreach ($this->notifications() as $key => [$notification, $notifiable]) {
            [$html] = $this->parts($notification, $notifiable);
            $stripped = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
            $stripped = preg_replace('/\sstyle="[^"]*"/i', '', $stripped);

            $this->assertStringContainsString(config('app.name'), $stripped, $key);
            $this->assertNotEmpty(trim(strip_tags($stripped)), "{$key}: no readable content after stripping CSS");
        }
    }

    public function test_no_notification_hides_content_from_a_css_less_reader(): void
    {
        foreach ($this->notifications() as $key => [$notification, $notifiable]) {
            [$html] = $this->parts($notification, $notifiable);
            $lower = mb_strtolower($html);
            foreach (['display:none', 'visibility:hidden', 'font-size:0', 'background-image:', 'mso-hide'] as $needle) {
                $this->assertStringNotContainsString($needle, $lower, "{$key}: {$needle}");
            }
        }
    }

    public function test_the_from_address_and_name_come_from_configuration(): void
    {
        $notifications = $this->notifications();
        config()->set('mail.from', ['address' => 'desk@configured.test', 'name' => 'Configured Desk']);
        foreach ($notifications as $key => [$notification, $notifiable]) {
            app('mailer')->getSymfonyTransport()->flush();
            app(MailChannel::class)->send($notifiable, $notification);
            $message = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
            $from = $message->getFrom()[0];
            $this->assertSame('desk@configured.test', $from->getAddress(), $key);
            $this->assertSame('Configured Desk', $from->getName(), $key);
        }

        config()->set('app.name', 'Acme Helpdesk');
        [$html, $text] = $this->parts(...$notifications['assigned']);
        $this->assertStringContainsString('Acme Helpdesk', $html);
        $this->assertStringContainsString('Acme Helpdesk', $text);
    }

    public function test_no_mail_template_or_notification_contains_a_literal_url(): void
    {
        $offenders = [];
        $paths = [...File::allFiles(resource_path('views/mail')), ...File::allFiles(app_path('Notifications'))];
        foreach ($paths as $file) {
            $contents = file_get_contents($file->getPathname());
            if (preg_match('#https?://#', (string) $contents)) {
                $offenders[] = $file->getRelativePathname();
            }
        }
        $this->assertSame([], $offenders);
    }

    public function test_no_notification_uses_the_markdown_pipeline(): void
    {
        $offenders = [];
        foreach (File::allFiles(app_path('Notifications')) as $file) {
            $contents = (string) file_get_contents($file->getPathname());
            if (preg_match('/->line\(|->action\(|->greeting\(|->markdown\(/', $contents)) {
                $offenders[] = $file->getRelativePathname();
            }
        }
        $this->assertSame([], $offenders);
    }

    public function test_no_notification_leaks_an_internal_note(): void
    {
        foreach ($this->notifications() as $key => [$notification, $notifiable]) {
            [$html, $text] = $this->parts($notification, $notifiable);
            $this->assertStringNotContainsString('INTERNAL-NOTE-CANARY', $html, $key);
            $this->assertStringNotContainsString('INTERNAL-NOTE-CANARY', $text, $key);
        }
    }

    public function test_no_notification_leaks_a_staff_email_address(): void
    {
        foreach ($this->notifications() as $key => [$notification, $notifiable]) {
            [$html, $text] = $this->parts($notification, $notifiable);
            if ($notifiable instanceof User && $notifiable->email === 'leak-me@staff.test') {
                // The recipient's own address legitimately appears in their body.
                continue;
            }
            $this->assertStringNotContainsString('leak-me@staff.test', $html, $key);
            $this->assertStringNotContainsString('leak-me@staff.test', $text, $key);
        }
    }

    public function test_no_notification_can_render_a_stack_trace(): void
    {
        foreach ($this->notifications() as $key => [$notification, $notifiable]) {
            [$html, $text] = $this->parts($notification, $notifiable);
            foreach (['Stack trace:', '#0 /', 'vendor/laravel', '.php:'] as $needle) {
                $this->assertStringNotContainsString($needle, $html, "{$key}: {$needle}");
                $this->assertStringNotContainsString($needle, $text, "{$key}: {$needle}");
            }
        }
    }

    public function test_the_requester_facing_emails_still_carry_no_link(): void
    {
        $notifications = $this->notifications();
        foreach (['created', 'status'] as $key) {
            [$notification, $notifiable] = $notifications[$key];
            [$html, $text] = $this->parts($notification, $notifiable);
            foreach ([$html, $text] as $part) {
                $this->assertStringNotContainsString('http', $part, $key);
                $this->assertStringNotContainsString('/tickets/', $part, $key);
                $this->assertStringNotContainsString((string) config('app.frontend_url'), $part, $key);
            }
        }
    }

    public function test_the_staff_facing_emails_still_carry_their_link(): void
    {
        $notifications = $this->notifications();
        foreach (['assigned', 'escalated'] as $key) {
            [$notification, $notifiable] = $notifications[$key];
            $ticket = $notification->ticket;
            $expectedUrl = rtrim((string) config('app.frontend_url'), '/').'/tickets/'.$ticket->getKey();
            [$html] = $this->parts($notification, $notifiable);
            $this->assertSame(1, substr_count($html, $expectedUrl), $key);
        }
    }

    public function test_the_summary_block_shows_only_what_each_notification_passes(): void
    {
        $notifications = $this->notifications();

        [$createdHtml, $createdText] = $this->parts(...$notifications['created']);
        foreach ([$createdHtml, $createdText] as $part) {
            $this->assertStringNotContainsString('Layout Urgent', $part);
            $this->assertStringNotContainsString(mb_strtolower('layout-urgent'), mb_strtolower($part));
        }

        [$statusHtml, $statusText] = $this->parts(...$notifications['status']);
        foreach ([$statusHtml, $statusText] as $part) {
            $this->assertStringNotContainsString('Layout Urgent', $part);
        }

        [$assignedHtml] = $this->parts(...$notifications['assigned']);
        $this->assertStringContainsString('Layout Urgent', $assignedHtml);

        [$escalatedHtml] = $this->parts(...$notifications['escalated']);
        $this->assertStringContainsString('Layout Urgent', $escalatedHtml);
    }
}
