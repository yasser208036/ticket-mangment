<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $this->withToken($this->tokenFor($user))->getJson(route('auth.me', absolute: false))->assertOk()
            ->assertJsonPath('user.id', $user->id)->assertJsonPath('user.email', $user->email)
            ->assertJsonStructure(['user' => ['id', 'name', 'email', 'role', 'is_active', 'created_at']]);
    }

    public function test_it_returns_the_role_as_a_string(): void
    {
        $user = User::factory()->admin()->create();
        $this->withToken($this->tokenFor($user))->getJson(route('auth.me', absolute: false))->assertJsonPath('user.role', 'admin');
    }

    public function test_the_payload_matches_the_login_responses_user_object(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse']);
        $login = $this->postJson(route('auth.login', absolute: false), ['email' => $user->email, 'password' => 'correct-horse']);
        $me = $this->withToken($login->json('token'))->getJson(route('auth.me', absolute: false));
        $this->assertSame($login->json('user'), $me->json('user'));
    }

    public function test_the_response_never_contains_the_password(): void
    {
        $user = User::factory()->create();
        $response = $this->withToken($this->tokenFor($user))->getJson(route('auth.me', absolute: false));
        $response->assertJsonMissingPath('user.password');
        $this->assertStringNotContainsString($user->fresh()->password, $response->getContent());
    }

    public function test_it_rejects_a_request_with_no_token(): void
    {
        $this->getJson(route('auth.me', absolute: false))->assertUnauthorized();
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('spa')->plainTextToken;
    }
}
