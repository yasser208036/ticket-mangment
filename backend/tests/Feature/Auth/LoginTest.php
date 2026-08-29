<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_a_token_and_the_user_for_valid_credentials(): void
    {
        User::factory()->create(['email' => 'agent@example.test', 'password' => 'correct-horse']);

        $this->postJson(route('auth.login', absolute: false), ['email' => 'agent@example.test', 'password' => 'correct-horse'])
            ->assertOk()->assertJsonStructure(['token', 'token_type', 'user' => ['id', 'name', 'email', 'role', 'is_active', 'created_at']])
            ->assertJsonPath('token_type', 'Bearer')->assertJsonPath('user.email', 'agent@example.test');
    }

    public function test_the_token_it_returns_authenticates_a_sanctum_request(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse']);
        Route::middleware('auth:sanctum')->get('/test-login-token', fn (Request $request) => ['id' => $request->user()->id]);
        $token = $this->postJson(route('auth.login', absolute: false), ['email' => $user->email, 'password' => 'correct-horse'])->json('token');

        $this->withToken($token)->getJson('/test-login-token')->assertOk()->assertJsonPath('id', $user->id);
    }

    public function test_it_persists_exactly_one_token_row(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse']);
        $this->postJson(route('auth.login', absolute: false), ['email' => $user->email, 'password' => 'correct-horse']);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame('spa', $user->tokens()->first()->name);
    }

    public function test_it_does_not_revoke_existing_tokens(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse']);
        $credentials = ['email' => $user->email, 'password' => 'correct-horse'];
        $this->postJson(route('auth.login', absolute: false), $credentials);
        $this->postJson(route('auth.login', absolute: false), $credentials);
        $this->assertSame(2, $user->tokens()->count());
    }

    public function test_the_response_never_contains_the_password(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse']);
        $response = $this->postJson(route('auth.login', absolute: false), ['email' => $user->email, 'password' => 'correct-horse']);
        $response->assertJsonMissingPath('user.password');
        $this->assertStringNotContainsString($user->password, $response->getContent());
    }

    public function test_the_route_is_versioned_under_api_v1(): void
    {
        $this->assertSame('/api/v1/auth/login', route('auth.login', absolute: false));
        $this->postJson('/api/auth/login')->assertNotFound();
        $this->getJson('/api/v1/auth/login')->assertStatus(405);
    }
}
