<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketActivityEvent;
use App\Events\TicketAssigned;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AssignTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $ticket = Ticket::factory()->create();
        $this->postJson($this->url($ticket), ['assigned_to' => null])->assertUnauthorized();
    }

    public function test_an_admin_assigns_and_writes_one_assigned_row(): void
    {
        $ticket = Ticket::factory()->create();
        $agent = User::factory()->agent()->create(['name' => 'Assignee Agent']);

        $this->asAdmin()->postJson($this->url($ticket), ['assigned_to' => $agent->getKey()])->assertOk();

        $row = TicketActivity::where('ticket_id', $ticket->getKey())->sole();
        $this->assertSame(TicketActivityEvent::Assigned, $row->event);
        $this->assertSame((string) $agent->getKey(), $row->new_value);
        $this->assertSame($agent->name, $row->meta['to_name']);
    }

    public function test_unassigning_writes_one_unassigned_row(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->assignedTo($agent)->create();

        $this->asAdmin()->postJson($this->url($ticket), ['assigned_to' => null])->assertOk();

        $row = TicketActivity::where('ticket_id', $ticket->getKey())->sole();
        $this->assertSame(TicketActivityEvent::Unassigned, $row->event);
        $this->assertSame((string) $agent->getKey(), $row->old_value);
        $this->assertNull($row->new_value);
    }

    public function test_reassigning_to_the_current_assignee_writes_nothing(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->assignedTo($agent)->create();

        $this->asAdmin()->postJson($this->url($ticket), ['assigned_to' => $agent->getKey()])->assertOk();

        $this->assertSame(0, TicketActivity::where('ticket_id', $ticket->getKey())->count());
    }

    public function test_assigning_to_an_admin_is_rejected(): void
    {
        $ticket = Ticket::factory()->create();
        $otherAdmin = User::factory()->admin()->create();

        $this->asAdmin()->postJson($this->url($ticket), ['assigned_to' => $otherAdmin->getKey()])
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
    }

    public function test_assigning_to_an_inactive_agent_is_rejected(): void
    {
        $ticket = Ticket::factory()->create();
        $inactive = User::factory()->agent()->inactive()->create();

        $this->asAdmin()->postJson($this->url($ticket), ['assigned_to' => $inactive->getKey()])
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
    }

    public function test_omitting_assigned_to_is_rejected(): void
    {
        $ticket = Ticket::factory()->create();

        $this->asAdmin()->postJson($this->url($ticket), [])
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
    }

    public function test_an_agent_is_forbidden(): void
    {
        $ticket = Ticket::factory()->create();
        $target = User::factory()->agent()->create();

        $this->asAgent()->postJson($this->url($ticket), ['assigned_to' => $target->getKey()])->assertForbidden();
        $this->assertSame(0, TicketActivity::where('ticket_id', $ticket->getKey())->count());
    }

    public function test_a_reason_is_stored_on_the_activity_row(): void
    {
        $ticket = Ticket::factory()->create();
        $agent = User::factory()->agent()->create();

        $this->asAdmin()->postJson($this->url($ticket), [
            'assigned_to' => $agent->getKey(),
            'reason' => 'Ahmed is on leave until Sunday',
        ])->assertOk();

        $this->assertSame('Ahmed is on leave until Sunday', $this->lastRow($ticket)->meta['reason']);
    }

    /**
     * Absent, not null. `array_key_exists('reason', $meta)` is the timeline's
     * signal that a human wrote something, so "no reason offered" must not
     * arrive looking like "reason cleared". An empty string reaches validation
     * as null, courtesy of ConvertEmptyStringsToNull.
     */
    public function test_a_blank_or_missing_reason_is_absent_from_meta(): void
    {
        $agent = User::factory()->agent()->create();

        $blank = Ticket::factory()->create();
        $this->asAdmin()->postJson($this->url($blank), ['assigned_to' => $agent->getKey(), 'reason' => ''])->assertOk();
        $this->assertArrayNotHasKey('reason', $this->lastRow($blank)->meta);

        $absent = Ticket::factory()->create();
        $this->asAdmin()->postJson($this->url($absent), ['assigned_to' => $agent->getKey()])->assertOk();
        $this->assertArrayNotHasKey('reason', $this->lastRow($absent)->meta);
    }

    public function test_a_reason_over_500_characters_is_rejected_and_nothing_is_applied(): void
    {
        $ticket = Ticket::factory()->create();
        $agent = User::factory()->agent()->create();

        $this->asAdmin()->postJson($this->url($ticket), [
            'assigned_to' => $agent->getKey(),
            'reason' => str_repeat('x', 501),
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');

        // Validation runs before the handler, so the assignment never happened.
        $this->assertNull($ticket->fresh()->assigned_to);
        $this->assertSame(0, TicketActivity::where('ticket_id', $ticket->getKey())->count());
    }

    /**
     * max:500 counts characters, not bytes, and ActivityRecorder passes
     * JSON_UNESCAPED_UNICODE, so the raw json column stays legible to anyone
     * reading it with a SQL client.
     */
    public function test_an_arabic_reason_round_trips_byte_identically(): void
    {
        $reason = str_repeat('م', 499).'🎫';
        $this->assertSame(500, mb_strlen($reason));

        $ticket = Ticket::factory()->create();
        $agent = User::factory()->agent()->create();
        $this->asAdmin()->postJson($this->url($ticket), ['assigned_to' => $agent->getKey(), 'reason' => $reason])->assertOk();

        $stored = $this->lastRow($ticket)->meta['reason'];
        $this->assertSame($reason, $stored);
        $this->assertSame(500, mb_strlen($stored));
        $this->assertStringContainsString($reason, DB::table('ticket_activities')->where('ticket_id', $ticket->getKey())->value('meta'));
    }

    public function test_unassigning_an_unassigned_ticket_writes_nothing(): void
    {
        $ticket = Ticket::factory()->create();
        $before = $ticket->fresh()->updated_at;

        $this->asAdmin()->postJson($this->url($ticket), ['assigned_to' => null])->assertOk();

        $this->assertSame(0, TicketActivity::where('ticket_id', $ticket->getKey())->count());
        $this->assertEquals($before, $ticket->fresh()->updated_at);
    }

    /**
     * Deliberate, and unfixable server-side: ConvertEmptyStringsToNull rewrites
     * '' to null before validation ever sees it, so no rule can tell an empty
     * form field from a considered unassign. The guard is the typed client --
     * TicketAssignDialog's `selected` is `number | undefined`, and its own spec
     * asserts the unassign path sends exactly null.
     */
    public function test_an_empty_string_assigned_to_unassigns(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->assignedTo($agent)->create();

        $this->asAdmin()->postJson($this->url($ticket), ['assigned_to' => ''])->assertOk();

        $this->assertNull($ticket->fresh()->assigned_to);
        $this->assertSame(TicketActivityEvent::Unassigned, $this->lastRow($ticket)->event);
    }

    public function test_a_zero_assigned_to_is_rejected(): void
    {
        $ticket = Ticket::factory()->create();

        $this->asAdmin()->postJson($this->url($ticket), ['assigned_to' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
    }

    public function test_a_reassignment_records_both_ids_and_the_reason(): void
    {
        Event::fake([TicketAssigned::class]);
        $from = User::factory()->agent()->create(['name' => 'Nadia']);
        $to = User::factory()->agent()->create(['name' => 'Omar']);
        $ticket = Ticket::factory()->assignedTo($from)->create();

        $this->asAdmin()->postJson($this->url($ticket), ['assigned_to' => $to->getKey(), 'reason' => 'Nadia is on leave'])->assertOk();

        $row = $this->lastRow($ticket);
        $this->assertSame(TicketActivityEvent::Assigned, $row->event);
        $this->assertSame((string) $from->getKey(), $row->old_value);
        $this->assertSame((string) $to->getKey(), $row->new_value);
        $this->assertSame('Nadia', $row->meta['from_name']);
        $this->assertSame('Omar', $row->meta['to_name']);
        $this->assertSame('Nadia is on leave', $row->meta['reason']);

        // The previous holder is notified zero times: nothing carries them.
        Event::assertDispatchedTimes(TicketAssigned::class, 1);
        Event::assertDispatched(TicketAssigned::class, fn (TicketAssigned $event): bool => $event->assigneeId === $to->getKey());
    }

    /**
     * The reason rides on the assignment, and an assignment that did not happen
     * has nothing to annotate. Documented in docs/api-contract.md so it is not
     * rediscovered as a bug.
     */
    public function test_reassigning_the_same_agent_drops_the_reason(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->assignedTo($agent)->create();

        $this->asAdmin()->postJson($this->url($ticket), ['assigned_to' => $agent->getKey(), 'reason' => 'Silently dropped'])->assertOk();

        $this->assertSame(0, TicketActivity::where('ticket_id', $ticket->getKey())->count());
    }

    public function test_unassigning_dispatches_no_event(): void
    {
        Event::fake([TicketAssigned::class]);
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->assignedTo($agent)->create();

        $this->asAdmin()->postJson($this->url($ticket), ['assigned_to' => null])->assertOk();

        Event::assertNotDispatched(TicketAssigned::class);
    }

    /**
     * The whole of "does not notify the previous one twice": a repeat request
     * -- a double-clicked button -- is a no-op getDirty() return, so the guard
     * on $changed is what stops the second email.
     */
    public function test_one_event_per_real_assignment_and_none_on_a_repeat(): void
    {
        Event::fake([TicketAssigned::class]);
        $ticket = Ticket::factory()->create();
        $agent = User::factory()->agent()->create();
        $admin = User::factory()->admin()->create();

        $payload = ['assigned_to' => $agent->getKey(), 'reason' => 'Yours now'];
        $this->withToken($this->tokenFor($admin))->postJson($this->url($ticket), $payload)->assertOk();
        $this->withToken($this->tokenFor($admin))->postJson($this->url($ticket), $payload)->assertOk();

        Event::assertDispatchedTimes(TicketAssigned::class, 1);
        Event::assertDispatched(TicketAssigned::class, fn (TicketAssigned $event): bool => $event->ticketId === $ticket->getKey()
            && $event->assigneeId === $agent->getKey()
            && $event->actorId === $admin->getKey()
            && $event->reason === 'Yours now');
    }

    /**
     * TM-57: notifications fire after commit, so an email can never reference a
     * row that rolled back. RefreshDatabase holds transaction level 1 for the
     * whole test; the controller's own DB::transaction() would make it 2. A
     * dispatch moved inside that closure therefore records 2 here and fails.
     */
    public function test_the_event_is_dispatched_outside_the_transaction(): void
    {
        Notification::fake();
        $levels = [];
        Event::listen(TicketAssigned::class, function () use (&$levels): void {
            $levels[] = DB::transactionLevel();
        });

        $ticket = Ticket::factory()->create();
        $agent = User::factory()->agent()->create();
        $this->asAdmin()->postJson($this->url($ticket), ['assigned_to' => $agent->getKey()])->assertOk();

        $this->assertSame([1], $levels);
    }

    public function test_a_failed_assignment_changes_nothing_and_dispatches_nothing(): void
    {
        Event::fake([TicketAssigned::class]);
        $ticket = Ticket::factory()->create();
        $agent = User::factory()->agent()->create();
        $this->mock(ActivityRecorder::class, function ($mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new \RuntimeException('boom'));
        });

        $this->asAdmin()->postJson($this->url($ticket), ['assigned_to' => $agent->getKey()])->assertServerError();

        $this->assertNull($ticket->fresh()->assigned_to);
        Event::assertNotDispatched(TicketAssigned::class);
    }

    private function lastRow(Ticket $ticket): TicketActivity
    {
        return TicketActivity::where('ticket_id', $ticket->getKey())->orderByDesc('id')->firstOrFail();
    }

    private function url(Ticket $ticket): string
    {
        return "/api/v1/tickets/{$ticket->id}/assign";
    }

    private function asAgent(): static
    {
        return $this->withToken($this->tokenFor(User::factory()->agent()->create()));
    }

    private function asAdmin(): static
    {
        return $this->withToken($this->tokenFor(User::factory()->admin()->create()));
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }
}
