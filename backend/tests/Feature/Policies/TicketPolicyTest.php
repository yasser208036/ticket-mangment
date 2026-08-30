<?php

namespace Tests\Feature\Policies;

use App\Models\Ticket;
use App\Models\User;
use App\Policies\TicketPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * The story's real authorization contract: view/update/changeStatus/escalate/
 * addNote/requestAssignment/assign/delete, exhaustively, across every actor
 * shape the product rules distinguish. One ticket fixture -- assigned to a
 * "holding" agent and authored by an "author" end user -- exercises every
 * branch each method can take without needing a matrix of tickets.
 */
class TicketPolicyTest extends TestCase
{
    use RefreshDatabase;

    private TicketPolicy $policy;

    private User $admin;

    private User $holdingAgent;

    private User $otherAgent;

    private User $authorEndUser;

    private User $otherEndUser;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->policy = new TicketPolicy;
        $this->admin = User::factory()->admin()->create();
        $this->holdingAgent = User::factory()->agent()->create();
        $this->otherAgent = User::factory()->agent()->create();
        $this->authorEndUser = User::factory()->endUser()->create();
        $this->otherEndUser = User::factory()->endUser()->create();

        $this->ticket = Ticket::factory()->assignedTo($this->holdingAgent)->create([
            'created_by' => $this->authorEndUser->getKey(),
        ]);
    }

    public function test_gate_resolves_policy(): void
    {
        $this->assertInstanceOf(TicketPolicy::class, Gate::getPolicyFor(Ticket::class));
    }

    /** @return array<string, bool> keyed by actor label */
    private function actors(): array
    {
        return [
            'admin' => $this->admin,
            'holding agent' => $this->holdingAgent,
            'other agent' => $this->otherAgent,
            'author end user' => $this->authorEndUser,
            'other end user' => $this->otherEndUser,
        ];
    }

    /**
     * One row per policy method: the expected true/false per actor, in the
     * same order as actors(). This is the contract table from Story 56 and
     * Story 58 read directly off the current TicketPolicy implementation.
     *
     * @return array<string, array<string, bool>>
     */
    private function expectations(): array
    {
        return [
            'view' => [
                'admin' => true,
                'holding agent' => true,
                'other agent' => false,
                'author end user' => true,
                'other end user' => false,
            ],
            'update' => [
                'admin' => true,
                'holding agent' => true,
                'other agent' => false,
                'author end user' => false,
                'other end user' => false,
            ],
            'changeStatus' => [
                'admin' => true,
                'holding agent' => true,
                'other agent' => false,
                'author end user' => false,
                'other end user' => false,
            ],
            'escalate' => [
                'admin' => true,
                'holding agent' => true,
                'other agent' => false,
                'author end user' => false,
                'other end user' => false,
            ],
            'addNote' => [
                'admin' => true,
                'holding agent' => true,
                'other agent' => false,
                'author end user' => false,
                'other end user' => false,
            ],
            // A pure role gate -- whether the ticket is still unassigned is
            // checked by StoreAssignmentRequestRequest, not here, so this is
            // true for every agent regardless of who (if anyone) holds the
            // ticket. See TicketPolicy::requestAssignment()'s own docblock.
            'requestAssignment' => [
                'admin' => false,
                'holding agent' => true,
                'other agent' => true,
                'author end user' => false,
                'other end user' => false,
            ],
            'assign' => [
                'admin' => true,
                'holding agent' => false,
                'other agent' => false,
                'author end user' => false,
                'other end user' => false,
            ],
            'delete' => [
                'admin' => true,
                'holding agent' => false,
                'other agent' => false,
                'author end user' => false,
                'other end user' => false,
            ],
        ];
    }

    public function test_the_full_contract_table(): void
    {
        foreach ($this->expectations() as $method => $perActor) {
            foreach ($this->actors() as $label => $actor) {
                $expected = $perActor[$label];
                $actual = $this->policy->{$method}($actor, $this->ticket);
                $this->assertSame(
                    $expected,
                    $actual,
                    "TicketPolicy::{$method}() for actor [{$label}] expected ".($expected ? 'true' : 'false').' but got '.($actual ? 'true' : 'false').'.'
                );
            }
        }
    }

    public function test_viewany_is_true_for_every_role_because_the_list_is_scoped_not_gated(): void
    {
        $this->assertTrue($this->policy->viewAny($this->admin));
        $this->assertTrue($this->policy->viewAny($this->holdingAgent));
        $this->assertTrue($this->policy->viewAny($this->authorEndUser));
    }

    public function test_only_an_end_user_may_create(): void
    {
        $this->assertFalse($this->policy->create($this->admin));
        $this->assertFalse($this->policy->create($this->holdingAgent));
        $this->assertTrue($this->policy->create($this->authorEndUser));
    }

    public function test_any_agent_can_view_and_request_an_unassigned_ticket(): void
    {
        $unassigned = Ticket::factory()->unassigned()->create();

        $this->assertTrue($this->policy->view($this->holdingAgent, $unassigned));
        $this->assertTrue($this->policy->view($this->otherAgent, $unassigned));
        $this->assertTrue($this->policy->requestAssignment($this->holdingAgent, $unassigned));
        $this->assertTrue($this->policy->requestAssignment($this->otherAgent, $unassigned));
    }

    public function test_an_end_user_cannot_add_a_note_even_on_their_own_ticket(): void
    {
        $own = Ticket::factory()->unassigned()->create(['created_by' => $this->authorEndUser->getKey()]);

        $this->assertTrue($this->policy->view($this->authorEndUser, $own));
        $this->assertFalse($this->policy->addNote($this->authorEndUser, $own));
    }

    public function test_gate_allows_and_denies_match_direct_policy_calls(): void
    {
        $gate = Gate::forUser($this->holdingAgent);
        $this->assertTrue($gate->allows('view', $this->ticket));
        $this->assertTrue($gate->allows('update', $this->ticket));
        $this->assertTrue($gate->allows('escalate', $this->ticket));
        $this->assertTrue($gate->denies('assign', $this->ticket));
        $this->assertTrue($gate->denies('delete', $this->ticket));

        $endUserGate = Gate::forUser($this->otherEndUser);
        $this->assertTrue($endUserGate->denies('view', $this->ticket));
        // An end user CAN create tickets -- that ability belongs to the role,
        // not to this specific ticket.
        $this->assertTrue($endUserGate->allows('create', Ticket::class));

        $agentGate = Gate::forUser($this->otherAgent);
        $this->assertTrue($agentGate->denies('create', Ticket::class));
    }
}
