<?php

namespace Tests\Feature\Policies;

use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_gate_resolves_policy(): void
    {
        $this->assertInstanceOf(UserPolicy::class, Gate::getPolicyFor(User::class));
    }

    public function test_admin_can_view_any(): void
    {
        $this->assertTrue((new UserPolicy)->viewAny(User::factory()->admin()->create()));
    }

    public function test_agent_cannot_view_any(): void
    {
        $this->assertFalse((new UserPolicy)->viewAny(User::factory()->agent()->create()));
    }

    public function test_admin_can_view_other(): void
    {
        $this->assertTrue((new UserPolicy)->view(User::factory()->admin()->create(), User::factory()->agent()->create()));
    }

    public function test_agent_can_view_self(): void
    {
        $agent = User::factory()->agent()->create();
        $this->assertTrue((new UserPolicy)->view($agent, $agent->fresh()));
    }

    public function test_agent_cannot_view_other(): void
    {
        $this->assertFalse((new UserPolicy)->view(User::factory()->agent()->create(), User::factory()->agent()->create()));
    }

    public function test_admin_can_create(): void
    {
        $this->assertTrue((new UserPolicy)->create(User::factory()->admin()->create()));
    }

    public function test_agent_cannot_create(): void
    {
        $this->assertFalse((new UserPolicy)->create(User::factory()->agent()->create()));
    }

    public function test_admin_can_update(): void
    {
        $this->assertTrue((new UserPolicy)->update(User::factory()->admin()->create(), User::factory()->admin()->create()));
    }

    public function test_agent_cannot_update(): void
    {
        $agent = User::factory()->agent()->create();
        $this->assertFalse((new UserPolicy)->update($agent, User::factory()->agent()->create()));
    }

    public function test_agent_cannot_update_self(): void
    {
        $agent = User::factory()->agent()->create();
        $this->assertFalse((new UserPolicy)->update($agent, $agent));
    }

    public function test_admin_can_delete_another_user(): void
    {
        $this->assertTrue((new UserPolicy)->delete(User::factory()->admin()->create(), User::factory()->agent()->create()));
    }

    public function test_admin_cannot_delete_self(): void
    {
        $admin = User::factory()->admin()->create();
        $this->assertFalse((new UserPolicy)->delete($admin, $admin->fresh()));
    }

    public function test_agent_cannot_delete(): void
    {
        $this->assertFalse((new UserPolicy)->delete(User::factory()->agent()->create(), User::factory()->agent()->create()));
    }

    public function test_admin_can_reset_another_users_password(): void
    {
        $this->assertTrue((new UserPolicy)->resetPassword(User::factory()->admin()->create(), User::factory()->agent()->create()));
    }

    public function test_admin_cannot_reset_own_password_through_this_ability(): void
    {
        $admin = User::factory()->admin()->create();
        $this->assertFalse((new UserPolicy)->resetPassword($admin, $admin->fresh()));
    }

    public function test_agent_cannot_reset_anyones_password(): void
    {
        $this->assertFalse((new UserPolicy)->resetPassword(User::factory()->agent()->create(), User::factory()->agent()->create()));
    }

    public function test_deactivated_admin_keeps_policy_access(): void
    {
        $admin = User::factory()->admin()->create()->fill(['is_active' => false]);
        $this->assertTrue((new UserPolicy)->viewAny($admin));
    }

    public function test_gate_allows_controller_abilities(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->agent()->create();
        $gate = Gate::forUser($admin);
        $this->assertTrue($gate->allows('viewAny', User::class));
        $this->assertTrue($gate->allows('create', User::class));
        $this->assertTrue($gate->allows('view', $target));
        $this->assertTrue($gate->allows('update', $target));
        $this->assertTrue($gate->allows('delete', $target));
        $this->assertTrue($gate->allows('resetPassword', $target));
        $this->assertTrue($gate->denies('delete', $admin));
        $this->assertTrue($gate->denies('resetPassword', $admin));
    }
}
