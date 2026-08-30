<?php

namespace Tests\Feature\Admin;

use App\Enums\AssignmentRequestStatus;
use App\Enums\TicketActivityEvent;
use App\Events\TicketAssigned;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketAssignmentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AssignmentRequestReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function pendingRequest(?User $agent = null, ?Ticket $ticket = null): TicketAssignmentRequest
    {
        $agent ??= User::factory()->agent()->create();
        $ticket ??= Ticket::factory()->create();

        return TicketAssignmentRequest::query()->create([
            'ticket_id' => $ticket->getKey(),
            'user_id' => $agent->getKey(),
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_index_lists_pending_oldest_first_and_refuses_non_admins(): void
    {
        $older = $this->pendingRequest();
        $newer = $this->pendingRequest();

        $admin = User::factory()->admin()->create();
        $response = $this->withToken($this->tokenFor($admin))->getJson('/api/v1/admin/assignment-requests')->assertOk();
        $ids = $response->json('data.*.id');
        $this->assertSame([$older->getKey(), $newer->getKey()], $ids);

        Auth::forgetGuards();
        $this->withToken($this->tokenFor(User::factory()->agent()->create()))->getJson('/api/v1/admin/assignment-requests')->assertForbidden();
        Auth::forgetGuards();
        $this->withToken($this->tokenFor(User::factory()->endUser()->create()))->getJson('/api/v1/admin/assignment-requests')->assertForbidden();
    }

    public function test_index_filters_by_status(): void
    {
        $pending = $this->pendingRequest();
        $declined = $this->pendingRequest();
        $declined->forceFill(['status' => AssignmentRequestStatus::Declined])->save();

        $admin = User::factory()->admin()->create();
        $response = $this->withToken($this->tokenFor($admin))->getJson('/api/v1/admin/assignment-requests?status=declined')->assertOk();
        $this->assertSame([$declined->getKey()], $response->json('data.*.id'));
    }

    public function test_approve_assigns_the_ticket_and_records_the_activity(): void
    {
        Event::fake([TicketAssigned::class]);
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create();
        $request = $this->pendingRequest($agent, $ticket);
        $admin = User::factory()->admin()->create();

        $this->withToken($this->tokenFor($admin))->postJson("/api/v1/admin/assignment-requests/{$request->id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertSame($agent->getKey(), $ticket->fresh()->assigned_to);
        $request->refresh();
        $this->assertSame(AssignmentRequestStatus::Approved, $request->status);
        $this->assertSame($admin->getKey(), $request->decided_by);
        $this->assertNotNull($request->decided_at);

        $row = TicketActivity::where('ticket_id', $ticket->getKey())->where('event', TicketActivityEvent::Assigned)->sole();
        $this->assertSame('assignment_request', $row->meta['reason']);
        Event::assertDispatched(TicketAssigned::class, fn ($event) => $event->ticketId === $ticket->getKey() && $event->assigneeId === $agent->getKey());
    }

    public function test_approve_declines_every_other_pending_request_on_the_same_ticket(): void
    {
        $ticket = Ticket::factory()->create();
        $winner = $this->pendingRequest(ticket: $ticket);
        $loser = $this->pendingRequest(ticket: $ticket);
        $admin = User::factory()->admin()->create();

        $this->withToken($this->tokenFor($admin))->postJson("/api/v1/admin/assignment-requests/{$winner->id}/approve")->assertOk();

        $loser->refresh();
        $this->assertSame(AssignmentRequestStatus::Declined, $loser->status);
        $this->assertNotNull($loser->decision_note);
        $declinedRow = TicketActivity::where('ticket_id', $ticket->getKey())->where('event', TicketActivityEvent::AssignmentRequestDeclined)->sole();
        $this->assertSame($admin->getKey(), $declinedRow->user_id);
    }

    public function test_approve_is_refused_when_the_ticket_already_has_an_assignee(): void
    {
        $otherHolder = User::factory()->agent()->create();
        $ticket = Ticket::factory()->assignedTo($otherHolder)->create();
        $request = $this->pendingRequest(ticket: $ticket);
        $admin = User::factory()->admin()->create();

        $this->withToken($this->tokenFor($admin))->postJson("/api/v1/admin/assignment-requests/{$request->id}/approve")
            ->assertUnprocessable()->assertJsonValidationErrors('ticket');

        $this->assertSame(AssignmentRequestStatus::Pending, $request->fresh()->status);
        $this->assertSame($otherHolder->getKey(), $ticket->fresh()->assigned_to);
    }

    public function test_approve_is_refused_when_the_requesting_agent_is_deactivated(): void
    {
        $agent = User::factory()->agent()->inactive()->create();
        $request = $this->pendingRequest($agent);
        $admin = User::factory()->admin()->create();

        $this->withToken($this->tokenFor($admin))->postJson("/api/v1/admin/assignment-requests/{$request->id}/approve")
            ->assertUnprocessable()->assertJsonValidationErrors('user');
    }

    public function test_approve_twice_is_refused_the_second_time(): void
    {
        $request = $this->pendingRequest();
        $admin = User::factory()->admin()->create();
        $token = $this->tokenFor($admin);

        $this->withToken($token)->postJson("/api/v1/admin/assignment-requests/{$request->id}/approve")->assertOk();
        $this->withToken($token)->postJson("/api/v1/admin/assignment-requests/{$request->id}/approve")
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_decline_records_the_decision_and_leaves_the_ticket_unassigned(): void
    {
        Event::fake([TicketAssigned::class]);
        $ticket = Ticket::factory()->create();
        $request = $this->pendingRequest(ticket: $ticket);
        $admin = User::factory()->admin()->create();

        $this->withToken($this->tokenFor($admin))->postJson("/api/v1/admin/assignment-requests/{$request->id}/decline", ['note' => 'Not the right fit.'])
            ->assertOk()->assertJsonPath('data.status', 'declined');

        $request->refresh();
        $this->assertSame(AssignmentRequestStatus::Declined, $request->status);
        $this->assertSame('Not the right fit.', $request->decision_note);
        $this->assertNull($ticket->fresh()->assigned_to);

        TicketActivity::where('ticket_id', $ticket->getKey())->where('event', TicketActivityEvent::AssignmentRequestDeclined)->firstOrFail();
        Event::assertNotDispatched(TicketAssigned::class);
    }

    public function test_a_soft_deleted_tickets_request_is_404_on_approve(): void
    {
        $ticket = Ticket::factory()->create();
        $request = $this->pendingRequest(ticket: $ticket);
        $ticket->delete();
        $admin = User::factory()->admin()->create();

        $this->withToken($this->tokenFor($admin))->postJson("/api/v1/admin/assignment-requests/{$request->id}/approve")->assertNotFound();
    }
}
