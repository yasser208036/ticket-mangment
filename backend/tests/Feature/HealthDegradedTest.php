<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthDegradedTest extends TestCase
{
    public function test_it_returns_503_and_hides_the_reason_when_debug_is_off(): void
    {
        $this->breakTheDatabaseConnection();
        config()->set('app.debug', false);

        $this->getJson('/api/v1/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.database.ok', false)
            ->assertJsonPath('checks.database.error', 'unavailable');
    }

    public function test_it_surfaces_the_probe_error_when_debug_is_on(): void
    {
        $this->breakTheDatabaseConnection();
        config()->set('app.debug', true);

        $response = $this->getJson('/api/v1/health')->assertStatus(503);

        $this->assertNotSame('unavailable', $response->json('checks.database.error'));
    }

    private function breakTheDatabaseConnection(): void
    {
        config()->set('database.connections.mysql.host', '127.0.0.1');
        config()->set('database.connections.mysql.port', 1);
        DB::purge('mysql');
    }
}
