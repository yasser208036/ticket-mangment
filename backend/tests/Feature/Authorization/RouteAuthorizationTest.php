<?php

namespace Tests\Feature\Authorization;

use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const ACCESS = ['health' => 'public', 'auth.login' => 'public', 'auth.logout' => 'self', 'auth.me' => 'self', 'auth.password' => 'self', 'admin.users.index' => 'admin', 'admin.users.store' => 'admin', 'admin.users.show' => 'admin', 'admin.users.update' => 'admin', 'admin.users.destroy' => 'admin', 'admin.users.password' => 'admin', 'admin.workload' => 'admin', 'admin.assignment-requests.index' => 'admin', 'admin.assignment-requests.approve' => 'admin', 'admin.assignment-requests.decline' => 'admin', 'categories.index' => 'staff', 'categories.show' => 'staff', 'priorities.index' => 'staff', 'statuses.index' => 'staff', 'agents.index' => 'staff', 'tickets.index' => 'staff', 'tickets.stats' => 'staff', 'tickets.show' => 'staff', 'tickets.status' => 'staff-write', 'tickets.store' => 'user-policy', 'tickets.update' => 'staff-write', 'categories.store' => 'admin-policy', 'categories.update' => 'admin-policy', 'categories.destroy' => 'admin-policy', 'tickets.destroy' => 'admin-policy', 'tickets.assign' => 'admin-policy', 'tickets.assignment-requests.store' => 'agent-policy', 'tickets.escalate' => 'staff', 'tickets.activities' => 'staff', 'tickets.notes' => 'staff'];

    public function test_every_api_route_is_classified(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1')) {
                $this->assertArrayHasKey($route->getName(), self::ACCESS, $route->uri());
            }
        }
    }

    public function test_every_classified_route_exists(): void
    {
        $names = collect(Route::getRoutes())->map(fn ($route) => $route->getName())->all();
        foreach (array_keys(self::ACCESS) as $name) {
            $this->assertContains($name, $names);
        }
    }

    public function test_agent_refused_by_admin_routes(): void
    {
        $token = $this->tokenFor(User::factory()->agent()->create());
        foreach ($this->routesFor('admin') as $route) {
            $this->withToken($token)->json($route['method'], $route['uri'])->assertForbidden()->assertJsonPath('message', 'This action is unauthorized.');
        }
    }

    public function test_agent_refused_by_policy_admin_routes(): void
    {
        $token = $this->tokenFor(User::factory()->agent()->create());
        $category = Category::query()->create(['name' => 'Test', 'slug' => 'test']);
        $this->seed();
        $ticket = Ticket::factory()->create();
        foreach ($this->routesFor('admin-policy', $category->id, $ticket->id) as $route) {
            $this->withToken($token)->json($route['method'], $route['uri'])->assertForbidden();
        }
    }

    public function test_admin_refused_by_agent_policy_routes(): void
    {
        $this->seed();
        $token = $this->tokenFor(User::factory()->admin()->create());
        $ticket = Ticket::factory()->create();
        foreach ($this->routesFor('agent-policy', 999999, $ticket->id) as $route) {
            $this->withToken($token)->json($route['method'], $route['uri'])->assertForbidden();
        }
    }

    public function test_staff_refused_by_user_policy_routes(): void
    {
        $this->seed();
        foreach (['admin', 'agent'] as $role) {
            Auth::forgetGuards();
            $token = $this->tokenFor(User::factory()->{$role}()->create());
            foreach ($this->routesFor('user-policy') as $route) {
                $this->withToken($token)->json($route['method'], $route['uri'])->assertForbidden();
            }
        }
    }

    public function test_agent_reaches_staff_routes(): void
    {
        $token = $this->tokenFor(User::factory()->agent()->create());
        $this->withToken($token)->getJson('/api/v1/categories')->assertOk();
        $this->withToken($token)->getJson('/api/v1/priorities')->assertOk();
        $this->withToken($token)->getJson('/api/v1/statuses')->assertOk();
        $this->withToken($token)->getJson('/api/v1/tickets')->assertOk();
    }

    public function test_agent_reaches_staff_write_routes(): void
    {
        $token = $this->tokenFor(User::factory()->agent()->create());
        foreach ($this->routesFor('staff-write') as $route) {
            $this->assertNotSame(403, $this->withToken($token)->json($route['method'], $route['uri'])->status());
        }
    }

    public function test_policy_admin_routes_have_no_admin_middleware(): void
    {
        foreach ($this->routesFor('admin-policy') as $route) {
            $this->assertNotContains('admin', Route::getRoutes()->getByName($route['name'])->gatherMiddleware());
        }
    }

    public function test_admin_not_refused_by_admin_routes(): void
    {
        $token = $this->tokenFor(User::factory()->admin()->create());
        foreach ($this->routesFor('admin') as $route) {
            $this->assertNotSame(403, $this->withToken($token)->json($route['method'], $route['uri'])->status());
        }
    }

    public function test_agent_reaches_self_routes(): void
    {
        $token = $this->tokenFor(User::factory()->agent()->create());
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->withToken($token)->patchJson('/api/v1/auth/password')->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
    }

    public function test_unauthenticated_non_public_refused(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
        $this->patchJson('/api/v1/auth/password')->assertUnauthorized();
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();
        $this->getJson('/api/v1/categories')->assertUnauthorized();
    }

    public function test_public_routes_need_no_token(): void
    {
        $this->getJson('/api/v1/health')->assertOk();
        $this->postJson('/api/v1/auth/login')->assertStatus(422);
    }

    public function test_admin_routes_have_three_middlewares(): void
    {
        foreach ($this->routesFor('admin') as $route) {
            $found = Route::getRoutes()->getByName($route['name'])->gatherMiddleware();
            $this->assertContains('auth:sanctum', $found);
            $this->assertContains('active', $found);
            $this->assertContains('admin', $found);
        }
    }

    public function test_unknown_id_agent_forbidden_admin_not_found(): void
    {
        Auth::forgetGuards();
        $this->withToken($this->tokenFor(User::factory()->agent()->create()))->getJson('/api/v1/admin/users/999999')->assertForbidden();
        Auth::forgetGuards();
        $this->withToken($this->tokenFor(User::factory()->admin()->create()))->getJson('/api/v1/admin/users/999999')->assertNotFound();
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function routesFor(string $level, int $categoryId = 999999, int $ticketId = 999999): array
    {
        return collect(Route::getRoutes())->filter(fn ($route) => (self::ACCESS[$route->getName()] ?? null) === $level)->map(fn ($route) => ['name' => $route->getName(), 'method' => $route->methods()[0], 'uri' => route($route->getName(), ['user' => 999999, 'category' => $categoryId, 'ticket' => $ticketId, 'assignmentRequest' => 999999], absolute: false)])->values()->all();
    }
}
