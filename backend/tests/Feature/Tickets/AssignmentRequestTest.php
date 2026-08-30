<?php

namespace Tests\Feature\Tickets;

use App\Enums\AssignmentRequestStatus;
use App\Enums\TicketActivityEvent;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketAssignmentRequest;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssignmentRequestTest extends TestCase
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
        $this->postJson($this->url($ticket))->assertUnauthorized();
    }

    public function test_agent_requests_an_unassigned_ticket(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create();

        $this->withToken($this->tokenFor($agent))->postJson($this->url($ticket), ['note' => 'I have handled this kind of issue before.'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $row = TicketAssignmentRequest::query()->sole();
        $this->assertSame($ticket->getKey(), $row->ticket_id);
        $this->assertSame($agent->getKey(), $row->user_id);
        $this->assertSame(AssignmentRequestStatus::Pending, $row->status);

        $activity = TicketActivity::where('ticket_id', $ticket->getKey())->where('event', TicketActivityEvent::AssignmentRequested)->sole();
        $this->assertSame($agent->getKey(), $activity->user_id);
        $this->assertSame('I have handled this kind of issue before.', $activity->meta['note']);
    }

    public function test_requesting_an_assigned_ticket_is_rejected(): void
    {
        $holder = User::factory()->agent()->create();
        $ticket = Ticket::factory()->assignedTo($holder)->create();
        $agent = User::factory()->agent()->create();

        $this->withToken($this->tokenFor($agent))->postJson($this->url($ticket))
            ->assertUnprocessable()->assertJsonValidationErrors('ticket');

        $this->assertSame(0, TicketAssignmentRequest::count());
    }

    public function test_the_same_agent_cannot_request_the_same_ticket_twice(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create();
        $token = $this->tokenFor($agent);

        $this->withToken($token)->postJson($this->url($ticket))->assertCreated();
        $this->withToken($token)->postJson($this->url($ticket))
            ->assertUnprocessable()->assertJsonValidationErrors('ticket');

        $this->assertSame(1, TicketAssignmentRequest::count());
    }

    public function test_two_different_agents_may_both_request_the_same_ticket(): void
    {
        $ticket = Ticket::factory()->create();
        $first = User::factory()->agent()->create();
        $second = User::factory()->agent()->create();

        $this->withToken($this->tokenFor($first))->postJson($this->url($ticket))->assertCreated();
        Auth::forgetGuards();
        $this->withToken($this->tokenFor($second))->postJson($this->url($ticket))->assertCreated();

        $this->assertSame(2, TicketAssignmentRequest::count());
    }

    public function test_admin_and_end_user_are_refused(): void
    {
        $ticket = Ticket::factory()->create();

        $this->withToken($this->tokenFor(User::factory()->admin()->create()))->postJson($this->url($ticket))->assertForbidden();
        Auth::forgetGuards();
        $this->withToken($this->tokenFor(User::factory()->endUser()->create()))->postJson($this->url($ticket))->assertForbidden();
    }

    public function test_claim_route_no_longer_exists(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create();

        $this->withToken($this->tokenFor($agent))->postJson("/api/v1/tickets/{$ticket->id}/claim")->assertNotFound();
    }

    public function test_a_note_over_500_characters_is_rejected(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create();

        $this->withToken($this->tokenFor($agent))->postJson($this->url($ticket), ['note' => str_repeat('a', 501)])
            ->assertUnprocessable()->assertJsonValidationErrors('note');
    }

    /**
     * The 'claimed' activity event is historical -- no live code path writes
     * it any more, but the trail is append-only and the round-trip test in
     * ActivityCoverageTest still writes one directly through the recorder.
     */
    public function test_claimed_event_still_round_trips_through_the_enum(): void
    {
        $ticket = Ticket::factory()->create();
        DB::transaction(fn () => app(ActivityRecorder::class)->record($ticket->getKey(), TicketActivityEvent::Claimed));
        $this->assertNotNull(TicketActivityEvent::tryFrom('claimed'));
    }

    private function url(Ticket $ticket): string
    {
        return "/api/v1/tickets/{$ticket->id}/assignment-requests";
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }
}
