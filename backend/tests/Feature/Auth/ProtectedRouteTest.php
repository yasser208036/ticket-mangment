<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProtectedRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_routes_are_versioned_under_api_v1(): void
    {
        $this->assertSame('/api/v1/auth/logout', route('auth.logout', absolute: false));
        $this->assertSame('/api/v1/auth/me', route('auth.me', absolute: false));
        $this->postJson('/api/auth/logout')->assertNotFound();
    }

    public function test_the_methods_are_pinned(): void
    {
        $this->getJson(route('auth.logout', absolute: false))->assertStatus(405);
        $this->postJson(route('auth.me', absolute: false))->assertStatus(405);
    }

    public function test_every_protected_auth_route_requires_both_middlewares(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'auth.') || $route->getName() === 'auth.login') {
                continue;
            }

            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertContains('active', $route->gatherMiddleware());
        }
    }
}
