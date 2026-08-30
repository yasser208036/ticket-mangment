<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The self-lockout guard on PATCH /admin/users/{user}. Two rules: you cannot
 * deactivate or demote yourself, and nobody can remove the last active admin.
 */
class UserLockoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_cannot_deactivate_themselves(): void
    {
        $admin = $this->actingAsAdmin();
        $this->patchJson($this->url($admin), ['is_active' => false])
            ->assertUnprocessable()
            ->assertJsonPath('errors.is_active.0', 'You cannot deactivate your own account.');
        $this->assertTrue($admin->refresh()->is_active);
    }

    public function test_an_admin_cannot_demote_themselves(): void
    {
        $admin = $this->actingAsAdmin();
        $this->patchJson($this->url($admin), ['role' => 'agent'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.role.0', 'You cannot change your own role.');
        $this->assertSame(UserRole::Admin, $admin->refresh()->role);
    }

    public function test_an_admin_can_edit_their_own_name_and_email(): void
    {
        $admin = $this->actingAsAdmin();
        $this->patchJson($this->url($admin), ['name' => 'Renamed', 'email' => 'renamed@example.test'])->assertOk();
        $this->assertSame('Renamed', $admin->refresh()->name);
        $this->assertSame('renamed@example.test', $admin->email);
    }

    public function test_the_last_active_admin_cannot_be_deactivated(): void
    {
        $target = User::factory()->admin()->create();
        $this->actAsAdminWhoseRowWentInactive();
        $this->patchJson($this->url($target), ['is_active' => false])
            ->assertUnprocessable()
            ->assertJsonPath('errors.is_active.0', 'This is the last active administrator. Promote someone else first.');
        $this->assertTrue($target->refresh()->is_active);
    }

    public function test_the_last_active_admin_cannot_be_demoted(): void
    {
        $target = User::factory()->admin()->create();
        $this->actAsAdminWhoseRowWentInactive();
        $this->patchJson($this->url($target), ['role' => 'agent'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.role.0', 'This is the last active administrator. Promote someone else first.');
        $this->assertSame(UserRole::Admin, $target->refresh()->role);
    }

    public function test_the_last_active_admin_cannot_be_moved_to_role_user(): void
    {
        $target = User::factory()->admin()->create();
        $this->actAsAdminWhoseRowWentInactive();
        $this->patchJson($this->url($target), ['role' => 'user'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.role.0', 'This is the last active administrator. Promote someone else first.');
        $this->assertSame(UserRole::Admin, $target->refresh()->role);
    }

    public function test_a_second_admin_can_be_deactivated_when_a_third_remains(): void
    {
        $this->actingAsAdmin();
        User::factory()->admin()->create();
        $target = User::factory()->admin()->create();
        $this->patchJson($this->url($target), ['is_active' => false])->assertOk();
        $this->assertFalse($target->refresh()->is_active);
    }

    public function test_deactivating_an_agent_is_never_blocked(): void
    {
        $this->actingAsAdmin();
        $agent = User::factory()->agent()->create();
        $this->patchJson($this->url($agent), ['is_active' => false])->assertOk();
        $this->assertFalse($agent->refresh()->is_active);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * The last-admin rule only fires when the acting admin's own row is no
     * longer active — the interleaving the guard exists for: A deactivates B
     * while B deactivates A, both having passed their own checks. Reproduced
     * deterministically by taking the actor's row out from under a session
     * that has already authenticated, which is exactly what the race does.
     */
    private function actAsAdminWhoseRowWentInactive(): User
    {
        $actor = $this->actingAsAdmin();
        DB::table('users')->where('id', $actor->getKey())->update(['is_active' => false]);

        return $actor;
    }

    private function url(User $user): string
    {
        return route('admin.users.update', $user, absolute: false);
    }
}
