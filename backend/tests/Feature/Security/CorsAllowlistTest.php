<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

class CorsAllowlistTest extends TestCase
{
    public function test_config_never_allows_a_wildcard_origin(): void
    {
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertFalse(config('cors.supports_credentials'));
    }

    public function test_the_configured_frontend_origin_is_allowed_and_an_arbitrary_one_is_not(): void
    {
        $allowed = config('cors.allowed_origins')[0];

        $this->withHeaders(['Origin' => $allowed])->getJson('/api/v1/health')
            ->assertHeader('Access-Control-Allow-Origin', $allowed);

        $response = $this->withHeaders(['Origin' => 'https://evil.example'])->getJson('/api/v1/health');
        $this->assertNotSame('https://evil.example', $response->headers->get('Access-Control-Allow-Origin'));
    }
}
