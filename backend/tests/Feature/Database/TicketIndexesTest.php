<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TicketIndexesTest extends TestCase
{
    use RefreshDatabase;

    public function test_escalated_at_is_indexed(): void
    {
        $indexes = collect(Schema::getIndexes('tickets'))->pluck('name');
        $this->assertContains('tickets_escalated_at_index', $indexes);
    }

    public function test_escalation_level_is_indexed(): void
    {
        $indexes = collect(Schema::getIndexes('tickets'))->pluck('name');
        $this->assertContains('tickets_escalation_level_index', $indexes);
    }
}
