<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketActivityEvent;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteTicketTest extends TestCase
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
        $this->deleteJson($this->url($ticket))->assertUnauthorized();
    }

    public function test_an_admin_soft_deletes_and_writes_one_deleted_row(): void
    {
        $ticket = Ticket::factory()->create();

        $this->asAdmin()->deleteJson($this->url($ticket))->assertNoContent();

        $this->assertSoftDeleted('tickets', ['id' => $ticket->getKey()]);
        $row = TicketActivity::where('ticket_id', $ticket->getKey())->sole();
        $this->assertSame(TicketActivityEvent::Deleted, $row->event);
        $this->assertSame($ticket->reference, $row->meta['reference']);
        $this->assertSame($ticket->subject, $row->meta['subject']);
    }

    public function test_an_agent_is_forbidden_and_nothing_is_written(): void
    {
        $ticket = Ticket::factory()->create();

        $this->asAgent()->deleteJson($this->url($ticket))->assertForbidden();

        $this->assertDatabaseHas('tickets', ['id' => $ticket->getKey(), 'deleted_at' => null]);
        $this->assertSame(0, TicketActivity::where('ticket_id', $ticket->getKey())->count());
    }

    public function test_the_activity_row_survives_the_soft_delete(): void
    {
        $ticket = Ticket::factory()->create();

        $this->asAdmin()->deleteJson($this->url($ticket))->assertNoContent();

        $this->assertDatabaseHas('ticket_activities', ['ticket_id' => $ticket->getKey(), 'event' => 'deleted']);
    }

    public function test_a_nonexistent_ticket_is_404(): void
    {
        $this->asAdmin()->deleteJson('/api/v1/tickets/999999')->assertNotFound();
    }

    public function test_an_already_deleted_ticket_is_404(): void
    {
        $ticket = Ticket::factory()->create();
        $ticket->delete();

        $this->asAdmin()->deleteJson($this->url($ticket))->assertNotFound();
    }

    private function url(Ticket $ticket): string
    {
        return "/api/v1/tickets/{$ticket->id}";
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
