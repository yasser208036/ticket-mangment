<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UsersTableSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function attributes(string $email): array
    {
        return ['name' => 'Test', 'email' => $email, 'password' => 'secret'];
    }

    public function test_it_has_required_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('users', ['name', 'email', 'password', 'role', 'is_active', 'created_at', 'updated_at']));
    }

    public function test_role_is_mysql_enum(): void
    {
        $this->assertSame("enum('admin','agent','user')", Schema::getColumnType('users', 'role', true));
    }

    public function test_role_defaults_to_agent(): void
    {
        $this->assertSame('agent', collect(Schema::getColumns('users'))->firstWhere('name', 'role')['default']);
        DB::table('users')->insert($this->attributes('role@test'));
        $this->assertSame('agent', DB::table('users')->where('email', 'role@test')->value('role'));
    }

    public function test_is_active_defaults_true(): void
    {
        DB::table('users')->insert($this->attributes('active@test'));
        $this->assertSame(1, DB::table('users')->where('email', 'active@test')->value('is_active'));
    }

    public function test_invalid_role_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        DB::table('users')->insert([...$this->attributes('invalid@test'), 'role' => 'manager']);
    }

    public function test_email_is_unique(): void
    {
        DB::table('users')->insert($this->attributes('same@test'));
        $this->expectException(QueryException::class);
        DB::table('users')->insert($this->attributes('same@test'));
    }
}
