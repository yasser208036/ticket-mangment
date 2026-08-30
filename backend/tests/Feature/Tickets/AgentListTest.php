<?php

namespace Tests\Feature\Tickets;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/v1/agents -- the one staff list every authenticated role may read
 * (a role-`user` account needs it to name an agent at ticket-creation time),
 * so it deliberately carries no email, role or is_active.
 */
class AgentListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_returns_only_active_agents_ordered_by_name(): void
    {
        $zeta = User::factory()->agent()->create(['name' => 'Zeta Agent']);
        $alpha = User::factory()->agent()->create(['name' => 'Alpha Agent']);
        User::factory()->agent()->inactive()->create(['name' => 'Inactive Agent']);
        User::factory()->admin()->create(['name' => 'Some Admin']);
        User::factory()->endUser()->create(['name' => 'Some User']);

        $response = $this->withToken($this->tokenFor($alpha))->getJson('/api/v1/agents')->assertOk();

        $names = $response->json('data.*.name');
        $this->assertSame(['Alpha Agent', 'Zeta Agent'], $names);
        $ids = $response->json('data.*.id');
        $this->assertContains($alpha->getKey(), $ids);
        $this->assertContains($zeta->getKey(), $ids);
    }

    public function test_response_carries_only_id_and_name(): void
    {
        $agent = User::factory()->agent()->create();

        $response = $this->withToken($this->tokenFor($agent))->getJson('/api/v1/agents')->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $agent->getKey());
        $this->assertNotNull($row);
        $this->assertSame(['id', 'name'], array_keys($row));
        $this->assertArrayNotHasKey('email', $row);
        $this->assertArrayNotHasKey('role', $row);
        $this->assertArrayNotHasKey('is_active', $row);
    }

    public function test_reachable_by_admin_agent_and_end_user(): void
    {
        $this->withToken($this->tokenFor(User::factory()->admin()->create()))->getJson('/api/v1/agents')->assertOk();
        $this->withToken($this->tokenFor(User::factory()->agent()->create()))->getJson('/api/v1/agents')->assertOk();
        $this->withToken($this->tokenFor(User::factory()->endUser()->create()))->getJson('/api/v1/agents')->assertOk();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/agents')->assertUnauthorized();
    }

    public function test_inactive_agent_is_excluded(): void
    {
        $active = User::factory()->agent()->create();
        $inactive = User::factory()->agent()->inactive()->create();

        $ids = $this->withToken($this->tokenFor($active))->getJson('/api/v1/agents')->assertOk()->json('data.*.id');

        $this->assertContains($active->getKey(), $ids);
        $this->assertNotContains($inactive->getKey(), $ids);
    }

    public function test_non_agent_roles_are_excluded(): void
    {
        $agent = User::factory()->agent()->create();
        $admin = User::factory()->admin()->create();
        $endUser = User::factory()->endUser()->create();

        $ids = $this->withToken($this->tokenFor($agent))->getJson('/api/v1/agents')->assertOk()->json('data.*.id');

        $this->assertContains($agent->getKey(), $ids);
        $this->assertNotContains($admin->getKey(), $ids);
        $this->assertNotContains($endUser->getKey(), $ids);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }
}
