<?php

namespace Tests\Feature\Notifications;

use App\Events\TicketStatusChanged;
use App\Listeners\SendTicketStatusChangedNotification;
use App\Models\Requester;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketStatusChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TicketStatusChangedNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function url(Ticket $ticket): string
    {
        return route('tickets.status', $ticket);
    }

    private function statusId(string $slug): int
    {
        return Status::query()->where('slug', $slug)->firstOrFail()->getKey();
    }

    /**
     * Assigned to the shared per-test agent: changeStatus() now requires the
     * acting agent to hold the ticket (Story 56's TicketPolicy rewrite).
     */
    private function ticketAt(string $slug): Ticket
    {
        return Ticket::factory()->assignedTo($this->sharedAgent())->create(['status_id' => $this->statusId($slug)]);
    }

    private ?User $sharedAgentInstance = null;

    private function sharedAgent(): User
    {
        return $this->sharedAgentInstance ??= User::factory()->agent()->create();
    }

    private function asAgent(): static
    {
        return $this->actingAs($this->sharedAgent());
    }

    private function moveTo(Ticket $ticket, string $slug, ?string $resolution = null, ?string $reason = null)
    {
        $payload = array_filter([
            'status_id' => $this->statusId($slug),
            'resolution' => $resolution,
            'reason' => $reason,
        ], fn ($v) => $v !== null);

        return $this->asAgent()->postJson($this->url($ticket), $payload);
    }

    public function test_a_visible_transition_queues_one_notification_to_the_requester(): void
    {
        Notification::fake();
        $ticket = $this->ticketAt('open');
        $requester = $ticket->requester()->first();

        $this->moveTo($ticket, 'in-progress')->assertOk();

        Notification::assertSentTo($requester, TicketStatusChangedNotification::class);
        Notification::assertSentTimes(TicketStatusChangedNotification::class, 1);
    }

    /**
     * Fails if $from->getKey() is captured outside the transaction closure —
     * that would read the *new* status and every email would say "X -> X".
     */
    public function test_the_notification_carries_the_status_it_moved_from(): void
    {
        Notification::fake();
        $ticket = $this->ticketAt('open');

        $this->moveTo($ticket, 'in-progress')->assertOk();

        Notification::assertSentTo(
            $ticket->requester()->first(),
            TicketStatusChangedNotification::class,
            fn (TicketStatusChangedNotification $n) => $n->from->slug === 'open' && $n->to->slug === 'in-progress',
        );
    }

    public function test_an_internal_only_transition_queues_nothing(): void
    {
        Notification::fake();
        $ticket = $this->ticketAt('new');

        $this->moveTo($ticket, 'open')->assertOk();
        Notification::assertNothingSent();

        config()->set('notifications.requester.visible_statuses', ['open']);
        $secondTicket = $this->ticketAt('new');
        $this->moveTo($secondTicket, 'open')->assertOk();
        Notification::assertSentTo($secondTicket->requester()->first(), TicketStatusChangedNotification::class);
    }

    public function test_a_status_email_is_delayed_and_not_sent_during_the_request(): void
    {
        $ticket = $this->ticketAt('open');

        $this->moveTo($ticket, 'in-progress')->assertOk();

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $job = DB::table('jobs')->first();
        $this->assertGreaterThanOrEqual(300, $job->available_at - $job->created_at);

        $this->travelTo(now()->addSeconds(301));
        $this->artisan('queue:work', ['--once' => true]);

        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
    }

    /**
     * The workflow graph has no direct pending -> resolved edge, so the chain
     * below detours through in-progress a second time. That makes this the
     * A -> B -> A case named in the plan's edge cases: the first and third
     * moves both target "in-progress", so shouldSend() passes for both of
     * those jobs -- but only the later one is actually reached, because the
     * middle "pending" job is superseded by it first. One email goes out,
     * describing the final "resolved" move.
     */
    public function test_rapid_changes_produce_one_email_describing_the_final_status(): void
    {
        $ticket = $this->ticketAt('open');

        $this->moveTo($ticket, 'in-progress')->assertOk();
        $this->moveTo($ticket, 'pending')->assertOk();
        $this->moveTo($ticket, 'in-progress')->assertOk();
        $this->moveTo($ticket, 'resolved', resolution: 'Replaced the network cable.')->assertOk();

        $this->assertSame(4, DB::table('jobs')->count());

        $this->travelTo(now()->addSeconds(301));
        $this->artisan('queue:work', ['--once' => true]);
        $this->artisan('queue:work', ['--once' => true]);
        $this->artisan('queue:work', ['--once' => true]);
        $this->artisan('queue:work', ['--once' => true]);

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $body = (string) $messages->last()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString("In Progress \u{2192} Resolved", $body);
        $this->assertStringNotContainsString("\u{2192} Pending", $body);
    }

    public function test_a_zero_delay_sends_immediately_and_does_not_suppress(): void
    {
        config()->set('notifications.requester.delay_seconds', 0);
        $ticket = $this->ticketAt('open');

        $this->moveTo($ticket, 'in-progress')->assertOk();
        $this->artisan('queue:work', ['--once' => true]);
        $this->moveTo($ticket, 'pending')->assertOk();
        $this->artisan('queue:work', ['--once' => true]);

        $this->assertCount(2, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_the_listener_is_discovered(): void
    {
        Event::fake();

        Event::assertListening(TicketStatusChanged::class, SendTicketStatusChangedNotification::class);
    }

    public function test_a_refused_transition_dispatches_nothing(): void
    {
        Event::fake([TicketStatusChanged::class]);
        $ticket = $this->ticketAt('new');

        $this->moveTo($ticket, 'resolved', resolution: 'irrelevant, this edge is illegal')->assertUnprocessable();

        Event::assertNotDispatched(TicketStatusChanged::class);
    }

    public function test_should_send_is_false_once_the_ticket_has_moved_on(): void
    {
        $ticket = $this->ticketAt('in-progress');
        $from = Status::where('slug', 'open')->sole();
        $to = Status::where('slug', 'in-progress')->sole();
        $requester = $ticket->requester()->first();
        $notification = new TicketStatusChangedNotification($ticket, $from, $to, null);

        $this->assertTrue($notification->shouldSend($requester, 'mail'));

        $ticket->update(['status_id' => $this->statusId('pending')]);
        $this->assertFalse($notification->shouldSend($requester, 'mail'));
    }

    public function test_should_send_is_false_for_a_deleted_ticket(): void
    {
        $ticket = $this->ticketAt('in-progress');
        $from = Status::where('slug', 'open')->sole();
        $to = Status::where('slug', 'in-progress')->sole();
        $requester = $ticket->requester()->first();
        $notification = new TicketStatusChangedNotification($ticket, $from, $to, null);
        $ticket->delete();

        $this->assertFalse($notification->shouldSend($requester, 'mail'));
    }

    public function test_a_requester_with_a_blank_email_queues_nothing_and_logs(): void
    {
        Notification::fake();
        Log::spy();
        $requester = Requester::create(['name' => 'No Address', 'email' => '']);
        $ticket = Ticket::factory()->for($requester)->create(['status_id' => $this->statusId('open')]);

        TicketStatusChanged::dispatch($ticket->getKey(), $this->statusId('open'), $this->statusId('in-progress'), null);

        Notification::assertNothingSent();
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context) => $context['reference'] === $ticket->reference
        );
    }
}
