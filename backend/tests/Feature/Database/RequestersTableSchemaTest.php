<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RequestersTableSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_has_required_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('requesters', ['name', 'email', 'phone', 'company', 'created_at', 'updated_at']));
    }

    public function test_it_has_no_login_columns(): void
    {
        foreach (['password', 'role', 'is_active', 'remember_token'] as $column) {
            $this->assertFalse(Schema::hasColumn('requesters', $column));
        }
    }

    public function test_email_is_unique(): void
    {
        DB::table('requesters')->insert(['name' => 'One', 'email' => 'same@example.test']);
        $this->expectException(QueryException::class);
        DB::table('requesters')->insert(['name' => 'Two', 'email' => 'same@example.test']);
    }
}
