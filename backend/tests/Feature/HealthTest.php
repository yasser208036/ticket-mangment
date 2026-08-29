<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_ok_when_the_database_is_reachable(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonMissingPath('checks.database.error')
            ->assertJsonStructure([
                'status', 'app', 'environment', 'version', 'api', 'time',
                'checks' => ['database' => ['ok']],
            ]);
    }

    public function test_it_reports_the_configured_app_version(): void
    {
        config()->set('app.version', '9.9.9-test');

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('version', '9.9.9-test')
            ->assertJsonPath('api', 'v1');
    }

    public function test_the_route_is_versioned_under_api_v1(): void
    {
        $this->assertSame('/api/v1/health', route('health', absolute: false));
        $this->getJson('/api/health')->assertNotFound();
    }
}
