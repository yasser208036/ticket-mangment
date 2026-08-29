<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketActivityEvent;
use App\Enums\UserRole;
use App\Models\Priority;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TicketEscalateTest extends TestCase
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
        $this->postJson($this->url($ticket), ['reason' => 'A perfectly good reason.'])->assertUnauthorized();
    }

    public function test_escalating_without_a_reason_is_rejected(): void
    {
        $ticket = Ticket::factory()->create();
        $this->asAgent()->postJson($this->url($ticket), [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'Say why this ticket needs to be escalated.');
        $this->assertSame(0, $ticket->fresh()->escalation_level);
        $this->assertSame(0, TicketActivity::query()->where('ticket_id', $ticket->id)->count());
    }

    public function test_a_whitespace_only_reason_is_rejected_as_required(): void
    {
        $ticket = Ticket::factory()->create();
        $this->asAgent()->postJson($this->url($ticket), ['reason' => '   '])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'Say why this ticket needs to be escalated.');
    }

    public function test_a_short_reason_is_rejected(): void
    {
        $ticket = Ticket::factory()->create();
        $this->asAgent()->postJson($this->url($ticket), ['reason' => 'too short'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'The escalation reason must be at least 10 characters.');
        $this->asAgent()->postJson($this->url($ticket), ['reason' => str_repeat('a', 5001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_a_terminal_ticket_returns_422_not_403(): void
    {
        foreach (['resolved', 'closed'] as $slug) {
            $status = Status::query()->where('slug', $slug)->firstOrFail();
            $ticket = Ticket::factory()->create(['status_id' => $status->id]);
            $this->asAgent()->postJson($this->url($ticket), ['reason' => 'Needs escalation please.'])
                ->assertStatus(422)
                ->assertJsonPath('errors.status.0', "A {$status->name} ticket cannot be escalated.");
        }
    }

    public function test_can_escalate_is_false_on_a_terminal_ticket(): void
    {
        $resolved = Status::query()->where('slug', 'resolved')->firstOrFail();
        $terminalTicket = Ticket::factory()->create(['status_id' => $resolved->id]);
        $openTicket = Ticket::factory()->create();

        $this->asAgent()->getJson("/api/v1/tickets/{$terminalTicket->id}")->assertJsonPath('data.can.escalate', false);
        Auth::forgetGuards();
        $this->asAgent()->getJson("/api/v1/tickets/{$openTicket->id}")->assertJsonPath('data.can.escalate', true);
    }

    public function test_priority_id_and_assigned_to_are_prohibited(): void
    {
        $ticket = Ticket::factory()->create();
        $this->asAgent()->postJson($this->url($ticket), ['reason' => 'Valid enough reason here.', 'priority_id' => 4])
            ->assertUnprocessable()->assertJsonValidationErrors('priority_id');
        $this->asAgent()->postJson($this->url($ticket), ['reason' => 'Valid enough reason here.', 'assigned_to' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
    }

    public function test_escalation_raises_the_priority_one_level(): void
    {
        $low = Priority::query()->where('slug', 'low')->firstOrFail();
        $medium = Priority::query()->where('slug', 'medium')->firstOrFail();
        $high = Priority::query()->where('slug', 'high')->firstOrFail();

        $lowTicket = Ticket::factory()->create(['priority_id' => $low->id]);
        $mediumTicket = Ticket::factory()->create(['priority_id' => $medium->id]);
        $highTicket = Ticket::factory()->create(['priority_id' => $high->id]);

        $this->asAgent()->postJson($this->url($lowTicket), ['reason' => 'Escalating this low one.'])
            ->assertOk()->assertJsonPath('data.priority.slug', 'medium');
        Auth::forgetGuards();
        $this->asAgent()->postJson($this->url($mediumTicket), ['reason' => 'Escalating this medium one.'])
            ->assertOk()->assertJsonPath('data.priority.slug', 'high');
        Auth::forgetGuards();
        $this->asAgent()->postJson($this->url($highTicket), ['reason' => 'Escalating this high one.'])
            ->assertOk()->assertJsonPath('data.priority.slug', 'urgent');
    }

    public function test_urgent_does_not_overflow(): void
    {
        $urgent = Priority::query()->where('slug', 'urgent')->firstOrFail();
        $ticket = Ticket::factory()->create(['priority_id' => $urgent->id]);
        $this->asAgent()->postJson($this->url($ticket), ['reason' => 'Already urgent but still stuck.'])
            ->assertOk()->assertJsonPath('data.priority.slug', 'urgent');
        $row = TicketActivity::query()->where('ticket_id', $ticket->id)->where('event', TicketActivityEvent::Escalated->value)->firstOrFail();
        $this->assertSame('Urgent', $row->meta['from_priority']);
        $this->assertSame('Urgent', $row->meta['to_priority']);
    }

    public function test_a_ticket_held_by_an_active_admin_is_not_reassigned(): void
    {
        $holder = User::factory()->admin()->create();
        $ticket = Ticket::factory()->assignedTo($holder)->create();
        $agent = User::factory()->agent()->create();

        $this->withToken($this->tokenFor($agent))->postJson($this->url($ticket), ['reason' => 'Someone else should look at this.'])
            ->assertOk()->assertJsonPath('data.assignee.id', $holder->id);
        $this->assertSame(0, TicketActivity::query()->where('ticket_id', $ticket->id)->where('event', TicketActivityEvent::Assigned->value)->count());
    }

    public function test_an_admin_escalating_their_own_ticket_keeps_it(): void
    {
        $admin = User::factory()->admin()->create();
        $ticket = Ticket::factory()->assignedTo($admin)->create();

        $this->withToken($this->tokenFor($admin))->postJson($this->url($ticket), ['reason' => 'I need help but I keep it.'])
            ->assertOk()->assertJsonPath('data.assignee.id', $admin->id);
    }

    public function test_escalation_is_refused_when_no_active_admin_exists(): void
    {
        User::query()->where('role', 'admin')->update(['is_active' => false]);
        $ticket = Ticket::factory()->create();
        $this->asAgent()->postJson($this->url($ticket), ['reason' => 'Nobody is around to take this.'])
            ->assertStatus(422)
            ->assertJsonPath('errors.assigned_to.0', 'There is no active administrator to escalate to. Activate an admin account first.');
        $this->assertSame(0, $ticket->fresh()->escalation_level);
    }

    public function test_an_inactive_admin_assignee_is_replaced(): void
    {
        // AdminUserSeeder's own admin is otherwise an equally-unloaded
        // candidate; excluded here so the assertion is unambiguous about
        // which admin the routing picked.
        $this->deactivateSeededAdmin();
        $inactiveAdmin = User::factory()->admin()->inactive()->create();
        $liveAdmin = User::factory()->admin()->create();
        $ticket = Ticket::factory()->assignedTo($inactiveAdmin)->create();

        $this->asAgent()->postJson($this->url($ticket), ['reason' => 'Their old admin left the team.'])
            ->assertOk()->assertJsonPath('data.assignee.id', $liveAdmin->id);
    }

    public function test_the_least_loaded_active_admin_receives_it(): void
    {
        $this->deactivateSeededAdmin();
        $busy = User::factory()->admin()->create();
        $tiedLow = User::factory()->admin()->create();
        $tiedAlsoLow = User::factory()->admin()->create();
        $openStatus = Status::query()->where('slug', 'open')->firstOrFail();
        $terminalStatus = Status::query()->where('slug', 'resolved')->firstOrFail();

        Ticket::factory()->count(3)->assignedTo($busy)->create(['status_id' => $openStatus->id]);
        Ticket::factory()->assignedTo($tiedLow)->create(['status_id' => $openStatus->id]);
        Ticket::factory()->assignedTo($tiedAlsoLow)->create(['status_id' => $openStatus->id]);
        // Must not count: terminal and soft-deleted tickets on the low admins.
        Ticket::factory()->assignedTo($tiedLow)->create(['status_id' => $terminalStatus->id]);
        $trashed = Ticket::factory()->assignedTo($tiedAlsoLow)->create(['status_id' => $openStatus->id]);
        $trashed->delete();

        $ticket = Ticket::factory()->create();
        $winner = $tiedLow->id < $tiedAlsoLow->id ? $tiedLow : $tiedAlsoLow;

        $this->asAgent()->postJson($this->url($ticket), ['reason' => 'Routed to the least busy admin.'])
            ->assertOk()->assertJsonPath('data.assignee.id', $winner->id);
    }

    public function test_the_escalated_row_captures_the_reason_and_the_level(): void
    {
        $ticket = Ticket::factory()->create();
        $this->asAgent()->postJson($this->url($ticket), ['reason' => 'Capturing this reason exactly.'])->assertOk();

        $row = TicketActivity::query()->where('ticket_id', $ticket->id)->where('event', TicketActivityEvent::Escalated->value)->firstOrFail();
        $this->assertSame('escalation_level', $row->field);
        $this->assertSame('0', $row->old_value);
        $this->assertSame('1', $row->new_value);
        $this->assertSame('Capturing this reason exactly.', $row->meta['reason']);
        $this->assertArrayHasKey('from_priority', $row->meta);
        $this->assertArrayHasKey('to_priority', $row->meta);
    }

    public function test_the_assigned_companion_row_preserves_the_previous_holder(): void
    {
        $agentHolder = User::factory()->agent()->create();
        $ticket = Ticket::factory()->assignedTo($agentHolder)->create();
        $this->asAgent()->postJson($this->url($ticket), ['reason' => 'Escalating past the current agent.'])->assertOk();

        $row = TicketActivity::query()->where('ticket_id', $ticket->id)->where('event', TicketActivityEvent::Assigned->value)->firstOrFail();
        $this->assertSame('assigned_to', $row->field);
        $this->assertSame((string) $agentHolder->id, $row->old_value);
        $this->assertNotNull($row->new_value);
        $this->assertSame($agentHolder->name, $row->meta['from_name']);

        $unassigned = Ticket::factory()->create(['assigned_to' => null]);
        $this->asAgent()->postJson($this->url($unassigned), ['reason' => 'Escalating an unassigned ticket.'])->assertOk();
        $unassignedRow = TicketActivity::query()->where('ticket_id', $unassigned->id)->where('event', TicketActivityEvent::Assigned->value)->firstOrFail();
        $this->assertNull($unassignedRow->old_value);
    }

    public function test_a_second_escalation_increments_and_overwrites(): void
    {
        $ticket = Ticket::factory()->create();
        $this->asAgent()->postJson($this->url($ticket), ['reason' => 'First reason for escalation.'])->assertOk();
        Auth::forgetGuards();
        $this->asAgent()->postJson($this->url($ticket), ['reason' => 'Second and newer reason.'])
            ->assertOk()->assertJsonPath('data.escalation_level', 2);

        $ticket->refresh();
        $this->assertSame(2, $ticket->escalation_level);
        $this->assertSame('Second and newer reason.', $ticket->escalation_reason);

        $reasons = TicketActivity::query()->where('ticket_id', $ticket->id)->where('event', TicketActivityEvent::Escalated->value)->orderBy('id')->pluck('meta');
        $this->assertCount(2, $reasons);
        $this->assertSame('First reason for escalation.', $reasons[0]['reason']);
        $this->assertSame('Second and newer reason.', $reasons[1]['reason']);
    }

    public function test_escalation_is_atomic(): void
    {
        $ticket = Ticket::factory()->create();
        $this->mock(ActivityRecorder::class, function ($mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new \RuntimeException('boom'));
        });

        $before = $ticket->fresh()->only(['escalation_level', 'priority_id', 'assigned_to', 'escalated_at']);
        $this->asAgent()->postJson($this->url($ticket), ['reason' => 'This will blow up mid-way.'])->assertServerError();

        $this->assertSame($before, $ticket->fresh()->only(['escalation_level', 'priority_id', 'assigned_to', 'escalated_at']));
    }

    public function test_a_reason_is_stored_verbatim(): void
    {
        $reason = '<script>alert(1)</script> مرحبا بك';
        $ticket = Ticket::factory()->create();
        $this->asAgent()->postJson($this->url($ticket), ['reason' => $reason])->assertOk();

        $this->assertSame($reason, $ticket->fresh()->escalation_reason);
        $row = TicketActivity::query()->where('ticket_id', $ticket->id)->where('event', TicketActivityEvent::Escalated->value)->firstOrFail();
        $this->assertSame($reason, $row->meta['reason']);
    }

    public function test_escalation_changes_no_status_or_lifecycle_timestamp(): void
    {
        $ticket = Ticket::factory()->create();
        $before = $ticket->only(['status_id', 'resolved_at', 'closed_at', 'first_responded_at']);
        $this->asAgent()->postJson($this->url($ticket), ['reason' => 'Never touches the workflow.'])->assertOk();
        $this->assertSame($before, $ticket->fresh()->only(['status_id', 'resolved_at', 'closed_at', 'first_responded_at']));
    }

    public function test_the_response_omits_can(): void
    {
        $ticket = Ticket::factory()->create();
        $this->asAgent()->postJson($this->url($ticket), ['reason' => 'Checking the response shape.'])
            ->assertOk()
            ->assertJsonMissingPath('data.can')
            ->assertJsonPath('data.escalation_level', 1);
        $this->assertArrayHasKey('priority', $this->asAgent()->postJson($this->url(Ticket::factory()->create()), ['reason' => 'Second ticket for shape check.'])->json('data'));
    }

    public function test_a_nonexistent_and_a_soft_deleted_ticket_are_404(): void
    {
        $trashed = Ticket::factory()->create();
        $trashed->delete();

        $this->asAgent()->postJson($this->url(999999), ['reason' => 'Does not matter here.'])->assertNotFound();
        Auth::forgetGuards();
        $this->asAgent()->postJson($this->url($trashed), ['reason' => 'Does not matter here.'])->assertNotFound();
        Auth::forgetGuards();
        $this->asAdmin()->postJson($this->url(999999), ['reason' => 'Does not matter here.'])->assertNotFound();
    }

    private function url(Ticket|int $ticket): string
    {
        $id = $ticket instanceof Ticket ? $ticket->id : $ticket;

        return "/api/v1/tickets/{$id}/escalate";
    }

    private function deactivateSeededAdmin(): void
    {
        User::query()->where('role', UserRole::Admin)->update(['is_active' => false]);
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
