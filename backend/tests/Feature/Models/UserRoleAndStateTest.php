<?php

namespace Tests\Feature\Models;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserRoleAndStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_casts_to_enum(): void
    {
        $user = User::factory()->admin()->create()->refresh();
        $this->assertSame(UserRole::Admin, $user->role);
    }

    public function test_is_active_casts_to_boolean(): void
    {
        $user = User::factory()->create()->refresh();
        $this->assertTrue($user->is_active === true);
    }

    public function test_is_admin_matches_role(): void
    {
        $this->assertTrue(User::factory()->admin()->make()->isAdmin());
        $this->assertFalse(User::factory()->agent()->make()->isAdmin());
    }

    public function test_role_is_not_mass_assignable(): void
    {
        $user = (new User)->fill(['name' => 'x', 'email' => 'x@y.test', 'password' => 'secret', 'role' => UserRole::Admin]);
        $this->assertNotSame(UserRole::Admin, $user->role);
    }

    public function test_password_hashes_once(): void
    {
        $user = User::factory()->create(['password' => 'plain']);
        $this->assertTrue(Hash::check('plain', $user->password));
        $this->assertNotSame('plain', $user->password);
    }
}
