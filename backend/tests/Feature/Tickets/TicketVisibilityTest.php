<?php

namespace Tests\Feature\Tickets;

use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TicketPolicy and Ticket::scopeVisibleTo, exercised through the real
 * endpoints: admin sees everything, an agent sees only what is assigned to
 * them plus the unassigned queue, and an end user sees only what they filed.
 * No query parameter is allowed to widen any of that.
 */
class TicketVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_admin_sees_every_fixture_ticket(): void
    {
        $admin = User::factory()->admin()->create();
        $agentA = User::factory()->agent()->create();
        $agentB = User::factory()->agent()->create();
        $endUser = User::factory()->endUser()->create();

        $assignedToA = Ticket::factory()->assignedTo($agentA)->create();
        $unassigned = Ticket::factory()->unassigned()->create();
        $assignedToB = Ticket::factory()->assignedTo($agentB)->create(['created_by' => $endUser->getKey()]);

        $response = $this->withToken($this->tokenFor($admin))->getJson('/api/v1/tickets')->assertOk();
        $this->assertSame(3, $response->json('meta.total'));
        $ids = $response->json('data.*.id');
        $this->assertContains($assignedToA->getKey(), $ids);
        $this->assertContains($unassigned->getKey(), $ids);
        $this->assertContains($assignedToB->getKey(), $ids);
    }

    public function test_agent_sees_their_own_and_the_unassigned_queue_but_not_a_colleagues(): void
    {
        $agentA = User::factory()->agent()->create();
        $agentB = User::factory()->agent()->create();

        $mine = Ticket::factory()->assignedTo($agentA)->create();
        $unassigned = Ticket::factory()->unassigned()->create();
        $colleagues = Ticket::factory()->assignedTo($agentB)->create();

        $response = $this->withToken($this->tokenFor($agentA))->getJson('/api/v1/tickets')->assertOk();
        $ids = $response->json('data.*.id');
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertContains($mine->getKey(), $ids);
        $this->assertContains($unassigned->getKey(), $ids);
        $this->assertNotContains($colleagues->getKey(), $ids);
    }

    public function test_end_user_sees_only_the_ticket_they_created(): void
    {
        $endUser = User::factory()->endUser()->create();
        $otherEndUser = User::factory()->endUser()->create();
        $agent = User::factory()->agent()->create();

        $mine = Ticket::factory()->unassigned()->create(['created_by' => $endUser->getKey()]);
        // Assigned to an agent but authored by someone else -- must stay invisible.
        Ticket::factory()->assignedTo($agent)->create(['created_by' => $otherEndUser->getKey()]);
        // Unassigned, but authored by someone else -- the "unassigned queue" rule is agent-only.
        Ticket::factory()->unassigned()->create(['created_by' => $otherEndUser->getKey()]);

        $response = $this->withToken($this->tokenFor($endUser))->getJson('/api/v1/tickets')->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame([$mine->getKey()], $response->json('data.*.id'));
    }

    public function test_agent_is_forbidden_from_a_ticket_assigned_to_another_agent(): void
    {
        $agentA = User::factory()->agent()->create();
        $agentB = User::factory()->agent()->create();
        $ticket = Ticket::factory()->assignedTo($agentB)->create();

        $this->withToken($this->tokenFor($agentA))->getJson("/api/v1/tickets/{$ticket->getKey()}")->assertForbidden();
    }

    public function test_end_user_is_forbidden_from_a_ticket_they_did_not_create(): void
    {
        $endUser = User::factory()->endUser()->create();
        $ticket = Ticket::factory()->create();

        $this->withToken($this->tokenFor($endUser))->getJson("/api/v1/tickets/{$ticket->getKey()}")->assertForbidden();
    }

    public function test_admin_can_see_a_ticket_outside_every_staff_scope(): void
    {
        $admin = User::factory()->admin()->create();
        $agentB = User::factory()->agent()->create();
        $ticket = Ticket::factory()->assignedTo($agentB)->create();

        $this->withToken($this->tokenFor($admin))->getJson("/api/v1/tickets/{$ticket->getKey()}")->assertOk();
    }

    public function test_status_filter_never_widens_an_agents_scope_past_a_colleagues_ticket(): void
    {
        $agentA = User::factory()->agent()->create();
        $agentB = User::factory()->agent()->create();
        $status = Status::query()->firstOrFail();

        $mine = Ticket::factory()->assignedTo($agentA)->create(['status_id' => $status->getKey()]);
        $colleagues = Ticket::factory()->assignedTo($agentB)->create(['status_id' => $status->getKey()]);

        $response = $this->withToken($this->tokenFor($agentA))
            ->getJson('/api/v1/tickets?'.http_build_query(['status_id' => [$status->getKey()]]))
            ->assertOk();

        $ids = $response->json('data.*.id');
        $this->assertContains($mine->getKey(), $ids);
        $this->assertNotContains($colleagues->getKey(), $ids);
    }

    public function test_stats_scope_is_all_for_admin(): void
    {
        Ticket::factory()->count(2)->create();

        $this->withToken($this->tokenFor(User::factory()->admin()->create()))
            ->getJson('/api/v1/tickets/stats')
            ->assertOk()
            ->assertJsonPath('data.scope', 'all');
    }

    public function test_stats_scope_is_assigned_for_agent(): void
    {
        $agent = User::factory()->agent()->create();
        Ticket::factory()->assignedTo($agent)->create();
        Ticket::factory()->create();

        $this->withToken($this->tokenFor($agent))
            ->getJson('/api/v1/tickets/stats')
            ->assertOk()
            ->assertJsonPath('data.scope', 'assigned');
    }

    public function test_stats_scope_is_authored_and_unassigned_is_zero_for_end_user(): void
    {
        $endUser = User::factory()->endUser()->create();
        $agent = User::factory()->agent()->create();
        // The end user's own ticket is assigned, so their scoped "unassigned"
        // count is 0 -- a global unassigned count would leak the queue-wide
        // number an end user has no business seeing.
        Ticket::factory()->assignedTo($agent)->create(['created_by' => $endUser->getKey()]);
        Ticket::factory()->unassigned()->create();

        $this->withToken($this->tokenFor($endUser))
            ->getJson('/api/v1/tickets/stats')
            ->assertOk()
            ->assertJsonPath('data.scope', 'authored')
            ->assertJsonPath('data.unassigned', 0);
    }

    /**
     * An agent's visibility scope includes the unassigned queue, but the
     * dashboard's "My escalated tickets" card and its `assignee=me` link
     * both mean tickets the agent holds. Counting the scope wholesale would
     * show a number the linked list can never match.
     */
    public function test_stats_escalated_counts_only_tickets_the_agent_holds(): void
    {
        $agent = User::factory()->agent()->create();
        Ticket::factory()->assignedTo($agent)->create(['escalation_level' => 1]);
        Ticket::factory()->unassigned()->create(['escalation_level' => 1]);
        Ticket::factory()->create(['escalation_level' => 1]);

        $this->withToken($this->tokenFor($agent))
            ->getJson('/api/v1/tickets/stats')
            ->assertOk()
            ->assertJsonPath('data.escalated', 1);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }
}
