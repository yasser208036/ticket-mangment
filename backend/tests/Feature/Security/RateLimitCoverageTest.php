<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RateLimitCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_write_route_is_rate_limited(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1')) {
                continue;
            }
            if (! array_intersect($route->methods(), ['POST', 'PATCH', 'DELETE'])) {
                continue;
            }

            $throttled = array_filter(
                $route->gatherMiddleware(),
                fn (string $middleware) => str_starts_with($middleware, 'throttle:'),
            );

            $this->assertNotEmpty($throttled, "{$route->uri()} has no throttle middleware.");
        }
    }

    public function test_the_write_limiter_actually_returns_429(): void
    {
        $admin = User::factory()->admin()->create();

        for ($i = 1; $i <= 61; $i++) {
            $response = $this->actingAs($admin)->postJson(route('categories.store'), [
                'name' => "Rate Limit Category {$i}",
            ]);

            if ($i < 61) {
                $response->assertCreated();
            } else {
                $response->assertTooManyRequests()->assertHeader('Retry-After');
            }
        }
    }
}
