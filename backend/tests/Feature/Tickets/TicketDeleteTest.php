<?php

namespace Tests\Feature\Tickets;

use App\Models\Category;
use App\Models\Requester;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TicketDeleteTest extends TestCase
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

        $this->deleteJson("/api/v1/tickets/{$ticket->id}")->assertUnauthorized();
    }

    public function test_agent_is_forbidden(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->assignedTo($agent)->create();

        $this->withToken($this->tokenFor($agent))
            ->deleteJson("/api/v1/tickets/{$ticket->id}")
            ->assertForbidden();

        $this->assertNotNull(Ticket::find($ticket->id));
    }

    public function test_admin_can_soft_delete(): void
    {
        $ticket = Ticket::factory()->create();

        $this->asAdmin()->deleteJson("/api/v1/tickets/{$ticket->id}")->assertNoContent();
    }

    public function test_delete_is_soft_not_hard(): void
    {
        $ticket = Ticket::factory()->create();

        $this->asAdmin()->deleteJson("/api/v1/tickets/{$ticket->id}")->assertNoContent();

        $this->assertNull(Ticket::find($ticket->id));
        $trashed = Ticket::withTrashed()->find($ticket->id);
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
    }

    public function test_writes_one_deleted_activity_row(): void
    {
        $admin = User::factory()->admin()->create();
        $ticket = Ticket::factory()->create();

        $this->withToken($this->tokenFor($admin))->deleteJson("/api/v1/tickets/{$ticket->id}")->assertNoContent();

        $row = TicketActivity::where('ticket_id', $ticket->getKey())->where('event', 'deleted')->sole();
        $this->assertSame($admin->getKey(), $row->user_id);
        $this->assertNotNull($row->created_at);
        $this->assertSame($ticket->reference, $row->meta['reference']);
        $this->assertSame($ticket->subject, $row->meta['subject']);
    }

    public function test_pre_existing_activity_rows_survive(): void
    {
        $ticket = Ticket::factory()->create();
        TicketActivity::query()->insert([
            'ticket_id' => $ticket->getKey(), 'event' => 'created', 'meta' => json_encode(['reference' => $ticket->reference]),
            'created_at' => now(),
        ]);

        $this->asAdmin()->deleteJson("/api/v1/tickets/{$ticket->id}")->assertNoContent();

        $this->assertSame(2, TicketActivity::where('ticket_id', $ticket->getKey())->count());
    }

    public function test_excluded_from_the_list(): void
    {
        $ticket = Ticket::factory()->create();
        $before = $this->asAdmin()->getJson('/api/v1/tickets')->json('meta.total');

        $this->asAdmin()->deleteJson("/api/v1/tickets/{$ticket->id}")->assertNoContent();

        $response = $this->asAdmin()->getJson('/api/v1/tickets')->assertOk();
        $this->assertSame($before - 1, $response->json('meta.total'));
        $this->assertNotContains($ticket->id, $response->json('data.*.id'));
    }

    public function test_detail_route_returns_404_after_deletion(): void
    {
        $ticket = Ticket::factory()->create();

        $this->asAdmin()->deleteJson("/api/v1/tickets/{$ticket->id}")->assertNoContent();

        $this->asAdmin()->getJson("/api/v1/tickets/{$ticket->id}")->assertNotFound();
    }

    public function test_requester_and_master_data_are_untouched(): void
    {
        $ticket = Ticket::factory()->create();
        $requesterId = $ticket->requester_id;
        $categoryId = $ticket->category_id;
        $priorityId = $ticket->priority_id;
        $statusId = $ticket->status_id;

        $this->asAdmin()->deleteJson("/api/v1/tickets/{$ticket->id}")->assertNoContent();

        $this->assertNotNull(Requester::find($requesterId));
        $this->assertNotNull(Category::find($categoryId));
        $this->assertDatabaseHas('priorities', ['id' => $priorityId]);
        $this->assertDatabaseHas('statuses', ['id' => $statusId]);
    }

    public function test_deleting_twice_returns_404(): void
    {
        $ticket = Ticket::factory()->create();

        $this->asAdmin()->deleteJson("/api/v1/tickets/{$ticket->id}")->assertNoContent();
        $this->asAdmin()->deleteJson("/api/v1/tickets/{$ticket->id}")->assertNotFound();

        $this->assertSame(1, TicketActivity::where('ticket_id', $ticket->getKey())->where('event', 'deleted')->count());
    }

    public function test_missing_ticket_returns_404_for_admin_and_agent_alike(): void
    {
        $agent = User::factory()->agent()->create();

        $this->asAdmin()->deleteJson('/api/v1/tickets/999999')->assertNotFound();
        $this->withToken($this->tokenFor($agent))->deleteJson('/api/v1/tickets/999999')->assertNotFound();
    }

    public function test_can_delete_is_true_for_admin_and_false_for_agent(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->assignedTo($agent)->create();

        $this->asAdmin()->getJson("/api/v1/tickets/{$ticket->id}")->assertJsonPath('data.can.delete', true);
        Auth::forgetGuards();
        $this->withToken($this->tokenFor($agent))
            ->getJson("/api/v1/tickets/{$ticket->id}")
            ->assertJsonPath('data.can.delete', false);
    }

    public function test_can_delete_is_absent_from_the_list(): void
    {
        Ticket::factory()->create();

        $response = $this->asAdmin()->getJson('/api/v1/tickets')->assertOk();
        $this->assertArrayNotHasKey('can', $response->json('data.0'));
    }

    public function test_soft_deleted_ticket_still_blocks_its_category(): void
    {
        $category = Category::factory()->create();
        $ticket = Ticket::factory()->create(['category_id' => $category->id]);

        $this->asAdmin()->deleteJson("/api/v1/tickets/{$ticket->id}")->assertNoContent();

        $this->asAdmin()->deleteJson("/api/v1/categories/{$category->id}")->assertUnprocessable();
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function asAdmin(): static
    {
        return $this->withToken($this->tokenFor(User::factory()->admin()->create()));
    }
}
