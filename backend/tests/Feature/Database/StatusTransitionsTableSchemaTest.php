<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StatusTransitionsTableSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_has_required_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('status_transitions', ['from_status_id', 'to_status_id', 'required_role', 'created_at', 'updated_at']));
    }

    public function test_required_role_is_a_real_enum(): void
    {
        $this->seed();
        $ids = DB::table('statuses')->pluck('id', 'slug');
        $this->expectException(QueryException::class);
        DB::table('status_transitions')->insert([
            'from_status_id' => $ids['new'], 'to_status_id' => $ids['open'], 'required_role' => 'wizard',
        ]);
    }

    public function test_an_edge_cannot_be_duplicated(): void
    {
        $this->seed();
        $ids = DB::table('statuses')->pluck('id', 'slug');
        $this->expectException(QueryException::class);
        DB::table('status_transitions')->insert([
            'from_status_id' => $ids['new'], 'to_status_id' => $ids['open'],
        ]);
    }

    public function test_a_status_with_edges_cannot_be_deleted(): void
    {
        $this->seed();
        $this->expectException(QueryException::class);
        DB::table('statuses')->where('slug', 'reopened')->delete();
    }

    public function test_the_reverse_edge_is_allowed(): void
    {
        $this->seed();
        $ids = DB::table('statuses')->pluck('id', 'slug');
        $this->assertDatabaseHas('status_transitions', ['from_status_id' => $ids['open'], 'to_status_id' => $ids['pending']]);
        $this->assertDatabaseHas('status_transitions', ['from_status_id' => $ids['pending'], 'to_status_id' => $ids['open']]);
    }
}
