<?php

namespace Tests\Feature\Notifications;

use App\Events\TicketAssigned;
use App\Listeners\SendTicketAssignedNotification;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketAssignedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TicketAssignedNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_assignment_notifies_the_new_assignee_and_nobody_else(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $agentB = User::factory()->agent()->create();
        $agentC = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create();

        $this->actingAs($admin)->postJson(route('tickets.assign', $ticket), ['assigned_to' => $agentB->getKey()])
            ->assertOk();

        Notification::assertSentTo($agentB, TicketAssignedNotification::class);
        Notification::assertNotSentTo([$admin, $agentC], TicketAssignedNotification::class);
        Notification::assertSentTimes(TicketAssignedNotification::class, 1);
    }

    public function test_the_notification_is_queued_rather_than_sent_synchronously(): void
    {
        // No fakes: this is the test that proves AC5 under Story 43's database
        // queue driver. It would pass wrongly under QUEUE_CONNECTION=sync,
        // which is exactly why phpunit.xml no longer sets it.
        $admin = User::factory()->admin()->create();
        $agentB = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create();

        $this->actingAs($admin)->postJson(route('tickets.assign', $ticket), ['assigned_to' => $agentB->getKey()])
            ->assertOk();

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());

        $this->artisan('queue:work', ['--once' => true]);

        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_unassigning_notifies_nobody(): void
    {
        $admin = User::factory()->admin()->create();
        $agentB = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create();
        $this->actingAs($admin)->postJson(route('tickets.assign', $ticket), ['assigned_to' => $agentB->getKey()])->assertOk();

        Notification::fake();

        $this->actingAs($admin)->postJson(route('tickets.assign', $ticket), ['assigned_to' => null])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_assigning_to_yourself_notifies_nobody(): void
    {
        // Driven through the event, not the endpoint: Story 26's rules (an
        // admin-only route requiring an active *agent* target) make self-assign
        // unreachable over HTTP today. The listener guards it anyway so the
        // rule holds if that ever changes.
        Notification::fake();
        $user = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create();

        TicketAssigned::dispatch($ticket->getKey(), $user->getKey(), $user->getKey(), null);

        Notification::assertNothingSent();
    }

    public function test_a_repeated_assignment_notifies_only_once(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $agentB = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create();

        $this->actingAs($admin)->postJson(route('tickets.assign', $ticket), ['assigned_to' => $agentB->getKey()])->assertOk();
        $this->actingAs($admin)->postJson(route('tickets.assign', $ticket), ['assigned_to' => $agentB->getKey()])->assertOk();

        Notification::assertSentTimes(TicketAssignedNotification::class, 1);
    }

    public function test_an_inactive_or_missing_assignee_is_skipped(): void
    {
        Notification::fake();
        $ticket = Ticket::factory()->create();
        $inactiveAgent = User::factory()->agent()->inactive()->create();
        $actor = User::factory()->admin()->create();

        TicketAssigned::dispatch($ticket->getKey(), $inactiveAgent->getKey(), $actor->getKey(), null);
        TicketAssigned::dispatch($ticket->getKey(), 999999, $actor->getKey(), null);

        Notification::assertNothingSent();
    }

    public function test_the_listener_is_discovered(): void
    {
        Event::fake();

        Event::assertListening(TicketAssigned::class, SendTicketAssignedNotification::class);
    }

    public function test_a_deleted_ticket_is_skipped(): void
    {
        Notification::fake();
        $ticket = Ticket::factory()->create();
        $agent = User::factory()->agent()->create();
        $actor = User::factory()->admin()->create();
        $ticket->delete();

        TicketAssigned::dispatch($ticket->getKey(), $agent->getKey(), $actor->getKey(), null);

        Notification::assertNothingSent();
    }
}
