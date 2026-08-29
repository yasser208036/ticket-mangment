<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketActivityEvent;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
