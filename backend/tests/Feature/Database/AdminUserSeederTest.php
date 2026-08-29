<?php

namespace Tests\Feature\Database;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('seeding.admin', ['name' => 'Configured Admin', 'email' => 'configured@test', 'password' => 'configured-password']);
    }

    public function test_it_creates_configured_admin(): void
    {
        $this->seed(AdminUserSeeder::class);
        $user = User::sole();
        $this->assertSame('configured@test', $user->email);
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertTrue(Hash::check('configured-password', $user->password));
    }

    public function test_it_is_idempotent(): void
    {
        $this->seed(AdminUserSeeder::class);
        $this->seed(AdminUserSeeder::class);
        $this->assertSame(1, User::count());
    }

    public function test_it_preserves_changed_password(): void
    {
        $this->seed(AdminUserSeeder::class);
        $user = User::sole();
        $user->password = 'new-password';
        $user->save();
        $this->seed(AdminUserSeeder::class);
        $this->assertTrue(Hash::check('new-password', User::sole()->password));
    }

    public function test_it_preserves_deactivation(): void
    {
        $this->seed(AdminUserSeeder::class);
        $user = User::sole();
        $user->is_active = false;
        $user->save();
        $this->seed(AdminUserSeeder::class);
        $this->assertFalse(User::sole()->is_active);
    }

    public function test_it_requires_password(): void
    {
        config()->set('seeding.admin.password', null);
        $this->expectException(RuntimeException::class);
        $this->seed(AdminUserSeeder::class);
        $this->assertSame(0, User::count());
    }

    public function test_database_seeder_creates_only_admin(): void
    {
        config()->set('seeding.admin.email', 'database@test');
        $this->seed();
        $this->assertSame(1, User::count());
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }
}
