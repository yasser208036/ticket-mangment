<?php

namespace Tests\Feature\Notifications;

use App\Events\TicketEscalated;
use App\Listeners\SendTicketEscalatedNotification;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketEscalatedNotification;
use Database\Seeders\CategorySeeder;
use Database\Seeders\PrioritySeeder;
use Database\Seeders\StatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TicketEscalatedNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Not $this->seed(): AdminUserSeeder creates one admin, and this
        // story's recipient-count tests must own the exact admin population.
        $this->seed([CategorySeeder::class, PrioritySeeder::class, StatusSeeder::class]);
    }

    /** @return Collection<int, User> */
    private function admins(int $count): Collection
    {
        return User::factory()->admin()->count($count)->create();
    }

    private function statusId(string $slug): int
    {
        return Status::query()->where('slug', $slug)->firstOrFail()->getKey();
    }

    private function ticketAt(string $slug): Ticket
    {
        return Ticket::factory()->create(['status_id' => $this->statusId($slug)]);
    }

    private function escalate(Ticket $ticket, User $actor, string $reason): TestResponse
    {
        return $this->actingAs($actor)->postJson(route('tickets.escalate', $ticket), ['reason' => $reason]);
    }

    public function test_escalation_notifies_every_active_admin(): void
    {
        Notification::fake();
        $admins = $this->admins(3);
        $agent = User::factory()->agent()->create();
        $ticket = $this->ticketAt('open');

        $this->escalate($ticket, $agent, 'Customer is a VIP and has escalated internally.')->assertOk();

        Notification::assertSentTo($admins, TicketEscalatedNotification::class);
        Notification::assertSentTimes(TicketEscalatedNotification::class, 3);
    }

    /**
     * NotificationSender::queueNotification() loops the notifiables and
     * dispatches a SendQueuedNotifications job inside that loop, so three
     * admins produce three independent jobs rather than one fan-out job.
     */
    public function test_one_job_is_queued_per_admin_and_nothing_is_sent_during_the_request(): void
    {
        $this->admins(3);
        $agent = User::factory()->agent()->create();
        $ticket = $this->ticketAt('open');

        $this->escalate($ticket, $agent, 'Customer is a VIP and has escalated internally.')->assertOk();

        $this->assertSame(3, DB::table('jobs')->count());
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());

        $this->artisan('queue:work', ['--once' => true]);
        $this->artisan('queue:work', ['--once' => true]);
        $this->artisan('queue:work', ['--once' => true]);

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(3, $messages);
        $recipients = $messages->map(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress());
        $this->assertCount(3, $recipients->unique());
    }

    public function test_the_escalating_agent_is_not_notified(): void
    {
        Notification::fake();
        $this->admins(2);
        $agent = User::factory()->agent()->create();
        $ticket = $this->ticketAt('open');

        $this->escalate($ticket, $agent, 'Customer is a VIP and has escalated internally.')->assertOk();

        Notification::assertNotSentTo($agent, TicketEscalatedNotification::class);
        Notification::assertSentTimes(TicketEscalatedNotification::class, 2);
    }

    public function test_an_inactive_admin_is_not_notified(): void
    {
        Notification::fake();
        $this->admins(2);
        $inactive = User::factory()->admin()->inactive()->create();
        $agent = User::factory()->agent()->create();
        $ticket = $this->ticketAt('open');

        $this->escalate($ticket, $agent, 'Customer is a VIP and has escalated internally.')->assertOk();

        Notification::assertSentTimes(TicketEscalatedNotification::class, 2);
        Notification::assertNotSentTo($inactive, TicketEscalatedNotification::class);
    }

    public function test_an_escalating_admin_is_notified_by_default_and_excluded_when_configured(): void
    {
        Notification::fake();
        $otherAdmins = $this->admins(2);
        $escalatingAdmin = User::factory()->admin()->create();
        $ticket = $this->ticketAt('open');

        $this->escalate($ticket, $escalatingAdmin, 'Escalating my own ticket for visibility.')->assertOk();
        Notification::assertSentTo($escalatingAdmin, TicketEscalatedNotification::class);

        Notification::fake();
        config()->set('notifications.admin.notify_escalating_admin', false);
        $secondTicket = $this->ticketAt('open');
        $this->escalate($secondTicket, $escalatingAdmin, 'Escalating again, this time excluded.')->assertOk();

        Notification::assertNotSentTo($escalatingAdmin, TicketEscalatedNotification::class);
        Notification::assertSentTo($otherAdmins, TicketEscalatedNotification::class);
    }

    /**
     * Driven through the event rather than the endpoint: Story 35 refuses an
     * escalation with a 422 when no active admin exists, so this state is
     * unreachable over HTTP. It can still occur if every admin is deactivated
     * between the commit and the listener running.
     */
    public function test_no_active_admin_queues_nothing_and_logs(): void
    {
        Notification::fake();
        Log::spy();
        User::factory()->admin()->inactive()->create();
        $agent = User::factory()->agent()->create();
        $ticket = $this->ticketAt('open');

        TicketEscalated::dispatch($ticket->getKey(), $agent->getKey(), 1, 'irrelevant');

        Notification::assertNothingSent();
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context) => $context['reference'] === $ticket->reference
        );
    }

    public function test_a_refused_escalation_dispatches_nothing(): void
    {
        Event::fake([TicketEscalated::class]);
        $this->admins(1);
        $agent = User::factory()->agent()->create();
        $ticket = $this->ticketAt('resolved');

        $this->escalate($ticket, $agent, 'Trying to escalate a resolved ticket.')->assertUnprocessable();

        Event::assertNotDispatched(TicketEscalated::class);
    }

    public function test_the_listener_is_discovered(): void
    {
        Event::fake();

        Event::assertListening(TicketEscalated::class, SendTicketEscalatedNotification::class);
    }

    public function test_the_event_carries_the_new_level_and_the_reason(): void
    {
        Event::fake([TicketEscalated::class]);
        $this->admins(1);
        $agent = User::factory()->agent()->create();
        $ticket = $this->ticketAt('open');

        $this->escalate($ticket, $agent, 'First escalation reason.')->assertOk();
        $this->escalate($ticket, $agent, 'Second escalation reason.')->assertOk();

        Event::assertDispatched(TicketEscalated::class, fn (TicketEscalated $e) => $e->level === 1 && $e->reason === 'First escalation reason.');
        Event::assertDispatched(TicketEscalated::class, fn (TicketEscalated $e) => $e->level === 2 && $e->reason === 'Second escalation reason.');
    }

    public function test_a_deleted_ticket_or_actor_is_skipped(): void
    {
        Notification::fake();
        Log::spy();
        $this->admins(1);
        $ticket = $this->ticketAt('open');
        $agent = User::factory()->agent()->create();
        $ticket->delete();

        TicketEscalated::dispatch($ticket->getKey(), $agent->getKey(), 1, 'irrelevant');

        Notification::assertNothingSent();
        Log::shouldHaveReceived('warning')->once();
    }
}
