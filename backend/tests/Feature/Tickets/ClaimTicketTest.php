<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketActivityEvent;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ClaimTicketTest extends TestCase
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

    public function test_an_agent_claims_an_unassigned_ticket_and_writes_one_claimed_row(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->unassigned()->create();

        $this->withToken($this->tokenFor($agent))->postJson($this->url($ticket))
            ->assertOk()
            ->assertJsonPath('data.assignee.id', $agent->getKey());

        $row = TicketActivity::where('ticket_id', $ticket->getKey())->sole();
        $this->assertSame(TicketActivityEvent::Claimed, $row->event);
        $this->assertSame((string) $agent->getKey(), $row->new_value);
    }

    public function test_claiming_an_already_claimed_ticket_is_a_409_and_writes_nothing(): void
    {
        $holder = User::factory()->agent()->create(['name' => 'Current Holder']);
        $ticket = Ticket::factory()->assignedTo($holder)->create();
        $challenger = User::factory()->agent()->create();

        Auth::forgetGuards();
        $response = $this->withToken($this->tokenFor($challenger))->postJson($this->url($ticket))
            ->assertStatus(409);

        $this->assertStringContainsString('Current Holder', $response->json('message'));
        $this->assertSame(0, TicketActivity::where('ticket_id', $ticket->getKey())->count());
    }

    public function test_claiming_a_ticket_already_held_by_the_caller_is_ok_and_writes_nothing(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->assignedTo($agent)->create();

        $this->withToken($this->tokenFor($agent))->postJson($this->url($ticket))->assertOk();

        $this->assertSame(0, TicketActivity::where('ticket_id', $ticket->getKey())->count());
    }

    public function test_an_admin_is_forbidden(): void
    {
        $ticket = Ticket::factory()->unassigned()->create();

        $this->withToken($this->tokenFor(User::factory()->admin()->create()))
            ->postJson($this->url($ticket))->assertForbidden();

        $this->assertSame(0, TicketActivity::where('ticket_id', $ticket->getKey())->count());
    }

    private function url(Ticket $ticket): string
    {
        return "/api/v1/tickets/{$ticket->id}/claim";
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }
}
