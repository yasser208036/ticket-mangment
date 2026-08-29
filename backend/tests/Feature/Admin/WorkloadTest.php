<?php

namespace Tests\Feature\Admin;

use App\Models\Priority;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AgentWorkload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkloadTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        // TicketFactory's `created_by` defaults to a fresh `User::factory()->agent()`,
        // which would silently add an extra active agent to the cohort on every
        // ticket created here. Pin it to a fixed admin so it never counts.
        $this->creator = User::factory()->admin()->create();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
    }

    public function test_agent_is_forbidden(): void
    {
        $this->asAgent()->getJson($this->url())
            ->assertForbidden()
            ->assertJsonPath('message', 'This action is unauthorized.');
    }

    public function test_counts_are_split_by_priority_and_zero_filled(): void
    {
        $agent = $this->makeAgent();
        $this->openTicket($agent, 'low');
        $this->openTicket($agent, 'low');
        $this->openTicket($agent, 'high');
        $this->openTicket($agent, 'urgent');
        $this->openTicket($agent, 'urgent');
        $this->openTicket($agent, 'urgent');

        $data = $this->asAdmin()->getJson($this->url())->assertOk()->json('data');

        $this->assertSame(['Low', 'Medium', 'High', 'Urgent'], array_column($data['priorities'], 'name'));

        $row = $this->rowFor($data['agents'], $agent->getKey());
        $byPriority = collect($row['by_priority'])->keyBy('priority_id');
        $this->assertSame(2, $byPriority[$this->priorityId('low')]['count']);
        $this->assertSame(0, $byPriority[$this->priorityId('medium')]['count']);
        $this->assertSame(1, $byPriority[$this->priorityId('high')]['count']);
        $this->assertSame(3, $byPriority[$this->priorityId('urgent')]['count']);
    }

    public function test_idle_active_agent_appears_and_is_in_the_average(): void
    {
        // Measured spread from the plan: [4,0,1,3,5,4,12] -> mean 4.1428..., band 2.
        $idle = $this->makeAgent();
        $this->openTickets($idle, 0);
        $this->openTickets($this->makeAgent(), 4);
        $this->openTickets($this->makeAgent(), 1);
        $this->openTickets($this->makeAgent(), 3);
        $this->openTickets($this->makeAgent(), 5);
        $this->openTickets($this->makeAgent(), 4);
        $this->openTickets($this->makeAgent(), 12);

        $data = $this->asAdmin()->getJson($this->url())->assertOk()->json('data');
        $row = $this->rowFor($data['agents'], $idle->getKey());

        $this->assertSame(0, $row['open_total']);
        $this->assertTrue($row['in_average']);
        $this->assertSame('low', $row['load']);
    }

    public function test_inactive_agent_holding_open_tickets_is_surfaced(): void
    {
        $inactive = $this->makeAgent(active: false);
        $this->openTickets($inactive, 2);

        $data = $this->asAdmin()->getJson($this->url())->assertOk()->json('data');
        $row = $this->rowFor($data['agents'], $inactive->getKey());

        $this->assertNotNull($row);
        $this->assertFalse($row['in_average']);
        $this->assertNull($row['load']);
        $this->assertTrue($row['needs_reassignment']);
    }

    public function test_inactive_agent_with_no_open_tickets_is_absent(): void
    {
        $inactive = $this->makeAgent(active: false);

        $data = $this->asAdmin()->getJson($this->url())->assertOk()->json('data');

        $this->assertNull($this->rowFor($data['agents'], $inactive->getKey()));
    }

    public function test_admin_holding_open_tickets_is_surfaced(): void
    {
        $admin = $this->makeAdmin();
        $this->openTickets($admin, 1);

        $data = $this->asAdmin()->getJson($this->url())->assertOk()->json('data');
        $row = $this->rowFor($data['agents'], $admin->getKey());

        $this->assertNotNull($row);
        $this->assertFalse($row['in_average']);
        $this->assertTrue($row['needs_reassignment']);
    }

    public function test_empty_queue_gives_a_zero_average_and_all_normal(): void
    {
        $this->makeAgent();
        $this->makeAgent();

        $data = $this->asAdmin()->getJson($this->url())->assertOk()->json('data');

        $this->assertEquals(0.0, $data['average_open']);
        $this->assertSame(1, $data['band']);
        foreach ($data['agents'] as $row) {
            $this->assertSame('normal', $row['load']);
        }
    }

    public function test_no_active_agents_gives_a_null_average(): void
    {
        $inactive = $this->makeAgent(active: false);
        $this->openTickets($inactive, 3);

        $data = $this->asAdmin()->getJson($this->url())->assertOk()->json('data');

        $this->assertNull($data['average_open']);
        $this->assertNull($data['band']);
        $this->assertNull($this->rowFor($data['agents'], $inactive->getKey())['load']);
    }

    public function test_single_active_agent_is_never_high_or_low(): void
    {
        $agent = $this->makeAgent();
        $this->openTickets($agent, 20);

        $data = $this->asAdmin()->getJson($this->url())->assertOk()->json('data');

        $this->assertSame('normal', $this->rowFor($data['agents'], $agent->getKey())['load']);
    }

    public function test_soft_deleted_tickets_are_excluded(): void
    {
        $agent = $this->makeAgent();
        $tickets = [
            $this->openTicket($agent, 'high'),
            $this->openTicket($agent, 'high'),
            $this->openTicket($agent, 'high'),
        ];
        $tickets[0]->delete();

        $data = $this->asAdmin()->getJson($this->url())->assertOk()->json('data');
        $row = $this->rowFor($data['agents'], $agent->getKey());

        $this->assertSame(2, $row['open_total']);
        $cell = collect($row['by_priority'])->firstWhere('priority_id', $this->priorityId('high'));
        $this->assertSame(2, $cell['count']);
    }

    public function test_terminal_status_tickets_are_excluded(): void
    {
        $agent = $this->makeAgent();
        $this->openTickets($agent, 2);
        $this->ticket($agent, 'resolved');

        $data = $this->asAdmin()->getJson($this->url())->assertOk()->json('data');

        $this->assertSame(2, $this->rowFor($data['agents'], $agent->getKey())['open_total']);
    }

    public function test_unassigned_tickets_are_excluded(): void
    {
        $agent = $this->makeAgent();
        $this->openTickets($agent, 2);
        Ticket::factory()->create(['status_id' => $this->statusId('open'), 'assigned_to' => null, 'created_by' => $this->creator->getKey()]);

        $data = $this->asAdmin()->getJson($this->url())->assertOk()->json('data');

        $openTotal = array_sum(array_column($data['agents'], 'open_total'));
        $this->assertSame(2, $openTotal);
        foreach ($data['agents'] as $row) {
            $this->assertNotNull($row['user']['id']);
        }
    }

    public function test_load_bands_match_the_documented_rule(): void
    {
        $agentsByTotal = [];
        foreach ([12, 5, 4, 4, 3, 1, 0] as $total) {
            $agent = $this->makeAgent();
            $this->openTickets($agent, $total);
            $agentsByTotal[] = ['agent' => $agent, 'total' => $total];
        }

        $data = $this->asAdmin()->getJson($this->url())->assertOk()->json('data');

        $this->assertSame(4.1, $data['average_open']);
        $this->assertSame(2, $data['band']);

        $expected = [12 => 'high', 5 => 'normal', 4 => 'normal', 3 => 'normal', 1 => 'low', 0 => 'low'];
        foreach ($agentsByTotal as $entry) {
            $row = $this->rowFor($data['agents'], $entry['agent']->getKey());
            $this->assertSame($expected[$entry['total']], $row['load'], "total {$entry['total']} expected {$expected[$entry['total']]}");
        }
    }

    public function test_rows_are_ordered_by_name_then_id(): void
    {
        $first = User::factory()->agent()->create(['name' => 'Same Name']);
        $second = User::factory()->agent()->create(['name' => 'Same Name']);

        $data = $this->asAdmin()->getJson($this->url())->assertOk()->json('data');

        $ids = array_column(array_column($data['agents'], 'user'), 'id');
        $positions = array_flip($ids);
        $this->assertLessThan($positions[$second->getKey()], $positions[$first->getKey()]);
    }

    public function test_the_aggregate_is_one_query(): void
    {
        $agent = $this->makeAgent();
        $this->openTickets($agent, 3);

        DB::enableQueryLog();
        (new AgentWorkload)->overview();
        $baseline = count(DB::getQueryLog());
        DB::flushQueryLog();

        $this->makeAgent();
        DB::flushQueryLog();

        (new AgentWorkload)->overview();
        $withOneMoreAgent = count(DB::getQueryLog());

        $this->assertSame(4, $baseline);
        $this->assertSame(4, $withOneMoreAgent);
    }

    public function test_open_status_ids_matches_the_seeded_non_terminal_statuses(): void
    {
        $data = $this->asAdmin()->getJson($this->url())->assertOk()->json('data');

        $this->assertCount(5, $data['open_status_ids']);
        $terminalIds = Status::query()->where('is_terminal', true)->pluck('id')->all();
        foreach ($terminalIds as $terminalId) {
            $this->assertNotContains($terminalId, $data['open_status_ids']);
        }
    }

    public function test_user_block_carries_email(): void
    {
        $agent = $this->makeAgent();
        $this->openTickets($agent, 1);

        $data = $this->asAdmin()->getJson($this->url())->assertOk()->json('data');
        $row = $this->rowFor($data['agents'], $agent->getKey());

        $this->assertSame($agent->email, $row['user']['email']);
    }

    private function url(): string
    {
        return '/api/v1/admin/workload';
    }

    private function makeAgent(bool $active = true): User
    {
        return User::factory()->agent()->create(['is_active' => $active]);
    }

    private function makeAdmin(): User
    {
        return User::factory()->admin()->create();
    }

    private function openTicket(User $assignee, ?string $prioritySlug = null): Ticket
    {
        return $this->ticket($assignee, 'open', $prioritySlug);
    }

    private function openTickets(User $assignee, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->openTicket($assignee);
        }
    }

    private function ticket(User $assignee, string $statusSlug, ?string $prioritySlug = null): Ticket
    {
        return Ticket::factory()->assignedTo($assignee)->create([
            'status_id' => $this->statusId($statusSlug),
            'priority_id' => $prioritySlug === null ? Priority::query()->where('is_default', true)->value('id') : $this->priorityId($prioritySlug),
            'created_by' => $this->creator->getKey(),
        ]);
    }

    private function statusId(string $slug): int
    {
        return Status::query()->where('slug', $slug)->firstOrFail()->id;
    }

    private function priorityId(string $slug): int
    {
        return Priority::query()->where('slug', $slug)->firstOrFail()->id;
    }

    private function rowFor(array $agents, int $userId): ?array
    {
        foreach ($agents as $row) {
            if ($row['user']['id'] === $userId) {
                return $row;
            }
        }

        return null;
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
